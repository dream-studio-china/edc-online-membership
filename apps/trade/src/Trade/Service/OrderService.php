<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Core\Security\IdentityProfilePrincipalInterface;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\Specification;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use CrudPlatform\IntegrationContracts\Wallet\WalletTransferPortInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/** @extends BaseService<\App\Trade\Entity\Order> */
class OrderService extends BaseService implements OrderServiceInterface
{
    /**
     * @param iterable<PriceCalculatorInterface> $priceCalculators
     */
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('trade.price_calculator')]
        private readonly iterable $priceCalculators,
        private readonly ?WalletTransferPortInterface $walletTransferPort = null,
        private readonly ?InvoiceServiceInterface $invoiceService = null,
        private readonly ?TradeOutboxService $outboxService = null,
        #[Target('state_machine.order')]
        private readonly ?WorkflowInterface $workflow = null,
    ) {
        parent::__construct($container, Order::class);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $meta
     */
    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
    {
        $context = new PriceCalculationContext($items, $currency);
        $context->storeCode = $storeCode;
        $context->meta = $meta;
        $identity = $this->identitySnapshot();
        if ($identity !== null) {
            $context->meta['identity'] = $identity;
        } else {
            unset($context->meta['identity']);
        }

        $sortedCalculators = $this->getSortedCalculators();
        foreach ($sortedCalculators as $calculator) {
            $calculator->calculate($context);
        }

        return PriceCalculationResult::fromContext($context);
    }

    /**
     * @param list<array<string, mixed>> $calculatedItems
     * @param array<string, mixed>|null  $metadata
     */
    public function createOrder(array $calculatedItems, ?string $userUuid, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order
    {
        return $this->wrapInTransaction(function () use ($calculatedItems, $userUuid, $totalAmount, $currency, $notes, $metadata, $storeContext) {
            $order = new Order();
            $order->setUserUuid($userUuid);
            $order->setTotalAmount($totalAmount);
            $order->setCurrency($currency);
            $order->setNotes($notes);
            if ($storeContext !== null) {
                $metadata ??= [];
                $metadata['_store'] = $storeContext->toSnapshot();
            }
            $order->setMetadata($metadata);

            foreach ($calculatedItems as $item) {
                $orderItem = new OrderItem();
                if (isset($item['specification']) && $item['specification'] instanceof Specification) {
                    $orderItem->setSpecification($item['specification']);
                }
                $orderItem->setQuantity($item['quantity']);
                $orderItem->setUnitPrice($item['unitPrice']);
                $orderItem->setPrice($item['price']);

                if (isset($item['specSnapshot'])) {
                    $orderItem->setSpecSnapshot($item['specSnapshot']);
                }
                if (isset($item['productSnapshot'])) {
                    $orderItem->setProductSnapshot($item['productSnapshot']);
                }

                $order->addItem($orderItem);
            }

            $this->getEntityManager()->persist($order);
            $this->getEntityManager()->flush();

            if ($storeContext !== null) {
                if ($this->workflow === null || $this->outboxService === null) {
                    throw new \RuntimeException('Store order orchestration is not configured.');
                }
                if (!$this->workflow->can($order, 'store_submit')) {
                    throw new \RuntimeException('Order cannot be submitted for store acceptance.');
                }

                $this->workflow->apply($order, 'store_submit');
                $this->outboxService->record('trade.order.created.v1', 'trade_order', $order->getUuid(), [
                    'orderUuid' => $order->getUuid(),
                    'store' => $storeContext->toSnapshot(),
                    'customerUserUuid' => $order->getUserUuid(),
                    'currency' => $order->getCurrency(),
                    'totalAmount' => $order->getTotalAmount(),
                    'items' => array_map(static fn (OrderItem $item): array => [
                        'lineId' => $item->getUuid(),
                        'catalogReference' => $item->getSpecification()?->getUuid() ?? '',
                        'quantity' => $item->getQuantity(),
                        'unitPrice' => $item->getUnitPrice(),
                        'lineAmount' => $item->getPrice(),
                        'snapshot' => [
                            'specification' => $item->getSpecSnapshot() ?? [],
                            'product' => $item->getProductSnapshot() ?? [],
                        ],
                    ], $order->getItems()->toArray()),
                    'delivery' => is_array($metadata['delivery'] ?? null) ? $metadata['delivery'] : [],
                    'placedAt' => $order->getCreatedAt()->format(DATE_ATOM),
                ]);
            }

            return $order;
        });
    }

    public function pay(Order $order, int $systemWalletId, string $paymentMethod = 'wallet', ?string $referenceId = null): void
    {
        if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "confirmed" status to pay, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if ($this->walletTransferPort === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing payments.');
        }

        $userUuid = $order->getUserUuid();
        if ($userUuid === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $this->walletTransferPort->debitOwner(
            $userUuid,
            $order->getCurrency(),
            $systemWalletId,
            $order->getTotalAmount(),
            $referenceId ?? 'order-pay-' . $order->getUuid(),
            sprintf('Payment for order #%d', $order->getId() ?? 0),
        );

        $order->setPaidAt(new \DateTimeImmutable());
        $order->setPaymentMethod($paymentMethod);
    }

    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void
    {
        if ($order->getStatus() !== Order::STATUS_COMPLETED) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "completed" status to refund, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if ($this->walletTransferPort === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing refunds.');
        }

        $userUuid = $order->getUserUuid();
        if ($userUuid === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $this->walletTransferPort->creditOwner(
            $userUuid,
            $order->getCurrency(),
            $systemWalletId,
            $order->getTotalAmount(),
            $referenceId ?? 'order-refund-' . $order->getUuid(),
            sprintf('Refund for order #%d: %s', $order->getId() ?? 0, $reason),
        );

        $order->setRefundedAt(new \DateTimeImmutable());
        $order->setRefundReason($reason);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fulfill(Order $order, array $data): void
    {
        if ($order->getStatus() !== Order::STATUS_PAID) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "paid" status to fulfill, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if (isset($data['trackingNumber'])) {
            $order->setTrackingNumber($data['trackingNumber']);
        }
        if (isset($data['shippingAddress'])) {
            $order->setShippingAddress($data['shippingAddress']);
        }

        $order->setFulfilledAt(new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createPayment(Order $order, string $payment = Invoice::PAYMENT_MOCK, array $options = []): PaymentResult
    {
        if ($this->invoiceService === null) {
            throw new \RuntimeException('Payment module is not configured.');
        }
        if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
            throw new \RuntimeException('Only confirmed orders can start payment.');
        }

        $invoice = null;
        if ($order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
        }
        if (!$invoice instanceof Invoice) {
            $invoice = $this->invoiceService->createInvoice(new CreateInvoiceRequest(
                sourceType: 'trade_order',
                sourceId: $order->getUuid(),
                scene: Invoice::SCENE_ORDER,
                amount: $order->getTotalAmount(),
                currency: $order->getCurrency(),
                payerUuid: $order->getUserUuid(),
                subject: sprintf('Order #%d', $order->getId() ?? 0),
                description: $order->getNotes(),
                extraData: ['orderId' => $order->getId()],
            ));

            $order->setInvoiceId($invoice->getUuid());
            $order->setInvoiceNo($invoice->getOutTradeNo());
            $order->setPaymentStatus($invoice->getStatus());
            $this->update($order, []);
        }

        return $this->invoiceService->pay($invoice, $payment, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult
    {
        if ($this->invoiceService === null) {
            throw new \RuntimeException('Payment module is not configured.');
        }
        $invoice = null;
        if ($order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
        }
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('Order has no linked invoice.');
        }

        return $this->invoiceService->refund($invoice, $invoice->getAmount() - $invoice->getRefundedAmount(), $reason, $options);
    }

    public function cancel(Order $order): void
    {
        if ($this->invoiceService !== null && $order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
            if ($invoice instanceof Invoice) {
                $this->invoiceService->cancel($invoice, 'Order cancelled.');
            }
        }
    }

    /**
     * @return list<PriceCalculatorInterface>
     */
    private function getSortedCalculators(): array
    {
        $calculators = is_array($this->priceCalculators)
            ? $this->priceCalculators
            : iterator_to_array($this->priceCalculators);

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        return $calculators;
    }

    /** @return array{id: int|null, profileLevel: string|null}|null */
    private function identitySnapshot(): ?array
    {
        if (!$this->user instanceof IdentityProfilePrincipalInterface) {
            return null;
        }

        return [
            'id' => $this->user->getId(),
            'profileLevel' => $this->user->getProfileLevel(),
        ];
    }
}
