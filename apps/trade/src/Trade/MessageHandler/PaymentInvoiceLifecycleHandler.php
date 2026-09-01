<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Entity\TradeConsumedEvent;
use App\Trade\Repository\TradeConsumedEventRepository;
use App\Trade\Service\OrderServiceInterface;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceCancelled;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceFailed;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoicePaid;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceRefunded;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class PaymentInvoiceLifecycleHandler
{
    public function __construct(
        private TradeConsumedEventRepository $consumedEventRepository,
        private OrderServiceInterface $orderService,
        private EntityManagerInterface $entityManager,
        #[Target('state_machine.order')]
        private WorkflowInterface $workflow,
    ) {}

    #[AsMessageHandler(handles: PaymentInvoicePaid::class)]
    public function handlePaid(PaymentInvoicePaid $message): void { $this->handle($message->envelope, PaymentInvoicePaid::TYPE); }
    #[AsMessageHandler(handles: PaymentInvoiceFailed::class)]
    public function handleFailed(PaymentInvoiceFailed $message): void { $this->handle($message->envelope, PaymentInvoiceFailed::TYPE); }
    #[AsMessageHandler(handles: PaymentInvoiceCancelled::class)]
    public function handleCancelled(PaymentInvoiceCancelled $message): void { $this->handle($message->envelope, PaymentInvoiceCancelled::TYPE); }
    #[AsMessageHandler(handles: PaymentInvoiceRefunded::class)]
    public function handleRefunded(PaymentInvoiceRefunded $message): void { $this->handle($message->envelope, PaymentInvoiceRefunded::TYPE); }

    /** @param array<string, mixed> $envelope */
    private function handle(array $envelope, string $expectedType): void
    {
        [$eventId, $payload] = $this->validate($envelope, $expectedType);
        $hash = hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
        $this->entityManager->wrapInTransaction(function () use ($eventId, $expectedType, $payload, $hash): void {
            $existing = $this->consumedEventRepository->findOneByEventId($eventId);
            if ($existing !== null) {
                if (!hash_equals($existing->getPayloadHash(), $hash)) {
                    throw new \LogicException('Payment event ID was reused with a different payload.');
                }
                return;
            }
            $this->entityManager->persist(new TradeConsumedEvent($eventId, $expectedType . '.v1', $payload['invoiceUuid'], $hash));
            if ($payload['sourceType'] !== 'trade_order') {
                return;
            }
            $order = $this->orderService->get(['uuid' => $payload['sourceId']]);
            if (!$order instanceof Order) {
                return;
            }
            if (($order->getInvoiceId() !== null && $order->getInvoiceId() !== $payload['invoiceUuid']) || ($order->getInvoiceNo() !== null && $order->getInvoiceNo() !== $payload['outTradeNo'])) {
                throw new \LogicException('Payment invoice does not match the linked Trade order.');
            }
            if ($expectedType === PaymentInvoicePaid::TYPE && ($order->getTotalAmount() !== $payload['amount'] || $order->getCurrency() !== $payload['currency'])) {
                throw new \LogicException('Payment invoice amount or currency does not match the Trade order.');
            }
            $order->setInvoiceId($payload['invoiceUuid']);
            $order->setInvoiceNo($payload['outTradeNo']);
            $order->setPaymentStatus($payload['status']);
            $order->setPaymentMethod($payload['payment']);
            if ($expectedType === PaymentInvoicePaid::TYPE) {
                $order->setPaidAt($this->date($payload['paidAt']) ?? new \DateTimeImmutable());
                if ($this->workflow->can($order, 'pay')) {
                    $this->workflow->apply($order, 'pay');
                }
            }
            if ($expectedType === PaymentInvoiceRefunded::TYPE && $payload['status'] === 'refunded') {
                $order->setRefundedAt($this->date($payload['refundedAt']) ?? new \DateTimeImmutable());
                if ($this->workflow->can($order, 'refund')) {
                    $this->workflow->apply($order, 'refund');
                }
            }
            $this->entityManager->persist($order);
        });
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{0: string, 1: array{invoiceUuid: string, outTradeNo: string, sourceType: string, sourceId: string, status: string, amount: int, currency: string, payment: ?string, paidAt: ?string, refundedAt: ?string}}
     */
    private function validate(array $envelope, string $expectedType): array
    {
        $eventId = $envelope['eventId'] ?? null;
        $aggregateId = $envelope['aggregateId'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (($envelope['type'] ?? null) !== $expectedType || ($envelope['version'] ?? null) !== 1 || !is_string($eventId) || !is_string($aggregateId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payment invoice lifecycle envelope.');
        }
        foreach (['invoiceUuid', 'outTradeNo', 'sourceType', 'sourceId', 'status', 'currency'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new \InvalidArgumentException('Invalid payment invoice lifecycle payload.');
            }
        }
        $payment = $payload['payment'] ?? null;
        if ($aggregateId !== $payload['invoiceUuid'] || !is_int($payload['amount'] ?? null) || (!is_string($payment) && $payment !== null)) {
            throw new \InvalidArgumentException('Invalid payment invoice lifecycle payload.');
        }
        return [$eventId, [
            'invoiceUuid' => $payload['invoiceUuid'], 'outTradeNo' => $payload['outTradeNo'], 'sourceType' => $payload['sourceType'], 'sourceId' => $payload['sourceId'],
            'status' => $payload['status'], 'amount' => $payload['amount'], 'currency' => $payload['currency'], 'payment' => $payment,
            'paidAt' => is_string($payload['paidAt'] ?? null) ? $payload['paidAt'] : null,
            'refundedAt' => is_string($payload['refundedAt'] ?? null) ? $payload['refundedAt'] : null,
        ]];
    }

    private function date(?string $value): ?\DateTimeImmutable { return $value === null ? null : new \DateTimeImmutable($value); }
}
