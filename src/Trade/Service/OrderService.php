<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\Specification;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\TransferServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class OrderService extends BaseService implements OrderServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('trade.price_calculator')]
        private readonly iterable $priceCalculators,
        private readonly ?WalletRepository $walletRepository = null,
        private readonly ?TransferServiceInterface $transferService = null,
        private readonly ?InvoiceServiceInterface $invoiceService = null,
    ) {
        parent::__construct($container, Order::class);
    }

    public function calculatePrices(array $items, string $currency = 'CNY'): PriceCalculationResult
    {
        $context = new PriceCalculationContext($items, $currency);

        $sortedCalculators = $this->getSortedCalculators();
        foreach ($sortedCalculators as $calculator) {
            $calculator->calculate($context);
        }

        return PriceCalculationResult::fromContext($context);
    }

    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null): Order
    {
        return $this->wrapInTransaction(function () use ($calculatedItems, $user, $totalAmount, $currency, $notes) {
            $order = new Order();
            if ($user instanceof User) {
                $order->setUser($user);
            } elseif (is_array($user) && isset($user['id'])) {
                $order->setUser($this->getEntityManager()->getReference(User::class, $user['id']));
            }
            $order->setTotalAmount($totalAmount);
            $order->setCurrency($currency);
            $order->setNotes($notes);

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

        if ($this->walletRepository === null || $this->transferService === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing payments.');
        }

        $user = $order->getUser();
        if ($user === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $userWallet = $this->walletRepository->findByUserAndCurrency($user->getId(), $order->getCurrency());
        if ($userWallet === null) {
            throw new \RuntimeException(sprintf(
                'No %s wallet found for user #%d.',
                $order->getCurrency(),
                $user->getId(),
            ));
        }

        $this->transferService->transfer(
            $userWallet->getId(),
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

        if ($this->walletRepository === null || $this->transferService === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing refunds.');
        }

        $user = $order->getUser();
        if ($user === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $userWallet = $this->walletRepository->findByUserAndCurrency($user->getId(), $order->getCurrency());
        if ($userWallet === null) {
            throw new \RuntimeException(sprintf(
                'No %s wallet found for user #%d.',
                $order->getCurrency(),
                $user->getId(),
            ));
        }

        $this->transferService->transfer(
            $systemWalletId,
            $userWallet->getId(),
            $order->getTotalAmount(),
            $referenceId ?? 'order-refund-' . $order->getUuid(),
            sprintf('Refund for order #%d: %s', $order->getId() ?? 0, $reason),
        );

        $order->setRefundedAt(new \DateTimeImmutable());
        $order->setRefundReason($reason);
    }

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
                payer: $order->getUser(),
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
}
