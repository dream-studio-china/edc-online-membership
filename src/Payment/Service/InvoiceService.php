<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Core\Service\BaseService;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Exception\InvoiceInvalidTransitionException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class InvoiceService extends BaseService implements InvoiceServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        #[Target('state_machine.invoice')]
        private readonly WorkflowInterface $workflow,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        parent::__construct($container, Invoice::class);
    }

    public function createInvoice(CreateInvoiceRequest $request): Invoice
    {
        if ($request->amount < 0) {
            throw new \InvalidArgumentException('Invoice amount cannot be negative.');
        }

        return $this->wrapInTransaction(function () use ($request) {
            $invoice = new Invoice();
            $invoice->setSourceType($request->sourceType);
            $invoice->setSourceId($request->sourceId);
            $invoice->setScene($request->scene);
            $invoice->setAmount($request->amount);
            $invoice->setCurrency($request->currency);
            $invoice->setPayer($request->payer);
            $invoice->setSubject($request->subject);
            $invoice->setDescription($request->description);
            $invoice->setExtraData($request->extraData ?: null);

            $this->getEntityManager()->persist($invoice);

            return $invoice;
        });
    }

    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult
    {
        $gateway = $this->gatewayRegistry->get($payment);

        return $this->wrapInTransaction(function () use ($invoice, $payment, $options, $gateway) {
            if (!$this->workflow->can($invoice, 'start_pay')) {
                throw new InvoiceInvalidTransitionException($invoice, 'start_pay');
            }

            $invoice->setPayment($payment);
            if (isset($options['gateway'])) {
                $invoice->setGateway((string) $options['gateway']);
            }
            if (isset($options['tradeType'])) {
                $invoice->setTradeType((string) $options['tradeType']);
            }
            $this->workflow->apply($invoice, 'start_pay');
            $this->getEntityManager()->flush();

            $result = $gateway->pay($invoice, $options);
            $payload = $result->payload ?? [];
            if ($payload) {
                $invoice->appendExtraData('pay', $this->sanitizePayload($payload));
            }

            if ($result->status === Invoice::STATUS_PAID) {
                $this->markPaid($invoice, new PaymentNotifyResult(
                    payment: $payment,
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: $invoice->getAmount(),
                    currency: $invoice->getCurrency(),
                    transactionId: $payload['transactionId'] ?? null,
                    paidAt: new \DateTimeImmutable(),
                    rawData: $payload,
                ));
            }

            return $result;
        });
    }

    public function handleNotifyResult(PaymentNotifyResult $result): Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = $this->getRepository()->findOneBy(['outTradeNo' => $result->outTradeNo]);
        if (!$invoice) {
            throw new \RuntimeException(sprintf('Invoice %s not found.', $result->outTradeNo));
        }

        return match ($result->status) {
            Invoice::STATUS_PAID => $this->markPaid($invoice, $result),
            Invoice::STATUS_FAILED => $this->markFailed($invoice, $result),
            default => throw new \InvalidArgumentException(sprintf('Unsupported notify status "%s".', $result->status)),
        };
    }

    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return $invoice;
        }
        if ($invoice->getStatus() === Invoice::STATUS_CANCELLED) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }
        if ($invoice->getAmount() !== $result->amount || $invoice->getCurrency() !== strtoupper($result->currency)) {
            throw new InvoiceAmountMismatchException('Payment notify amount or currency does not match invoice.');
        }
        if (!$this->workflow->can($invoice, 'mark_paid')) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }

        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $invoice->setPayment($result->payment);
            $invoice->setTransactionId($result->transactionId);
            $invoice->setPaidAt($result->paidAt ?? new \DateTimeImmutable());
            $invoice->appendExtraData('notify', $this->sanitizePayload($result->rawData));
            $this->workflow->apply($invoice, 'mark_paid');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoicePaidEvent($invoice, $result));

            return $invoice;
        });
    }

    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return $invoice;
        }
        if (!$this->workflow->can($invoice, 'fail')) {
            throw new InvoiceInvalidTransitionException($invoice, 'fail');
        }

        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $invoice->appendExtraData('notify_failed', $this->sanitizePayload($result->rawData));
            $this->workflow->apply($invoice, 'fail');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceFailedEvent($invoice, $result));

            return $invoice;
        });
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (!$this->workflow->can($invoice, 'cancel')) {
            throw new InvoiceInvalidTransitionException($invoice, 'cancel');
        }

        return $this->wrapInTransaction(function () use ($invoice, $reason) {
            if ($reason !== null) {
                $invoice->appendExtraData('cancel', ['reason' => $reason]);
            }
            $invoice->setCancelledAt(new \DateTimeImmutable());
            $this->workflow->apply($invoice, 'cancel');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceCancelledEvent($invoice));

            return $invoice;
        });
    }

    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        $remaining = $invoice->getAmount() - $invoice->getRefundedAmount();
        if ($amount > $remaining) {
            throw new InvoiceAmountMismatchException('Refund amount exceeds paid remaining amount.');
        }
        if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)) {
            throw new InvoiceInvalidTransitionException($invoice, 'refund');
        }

        $payment = $invoice->getPayment() ?? throw new \RuntimeException('Invoice has no payment gateway.');
        $gateway = $this->gatewayRegistry->get($payment);

        return $this->wrapInTransaction(function () use ($invoice, $amount, $reason, $options, $gateway) {
            $result = $gateway->refund($invoice, $amount, $reason, $options);
            $newRefundedAmount = $invoice->getRefundedAmount() + $amount;
            $invoice->setRefundedAmount($newRefundedAmount);
            $invoice->appendExtraData('refund_' . ($result->refundId ?? count($invoice->getExtraData() ?? [])), $this->sanitizePayload($result->rawData));

            $transition = $newRefundedAmount >= $invoice->getAmount() ? 'refund' : 'partial_refund';
            if (!$this->workflow->can($invoice, $transition)) {
                throw new InvoiceInvalidTransitionException($invoice, $transition);
            }
            if ($transition === 'refund') {
                $invoice->setRefundedAt(new \DateTimeImmutable());
            }
            $this->workflow->apply($invoice, $transition);
            $this->getEntityManager()->flush();

            $finalResult = new PaymentRefundResult($invoice, $amount, $invoice->getStatus(), $result->refundId, $result->rawData);
            $this->dispatcher->dispatch(new InvoiceRefundedEvent($invoice, $finalResult));

            return $finalResult;
        });
    }

    public function findBySource(string $sourceType, string $sourceId): array
    {
        return $this->getRepository()->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId], ['id' => 'DESC']);
    }

    private function sanitizePayload(array $payload): array
    {
        foreach (['password', 'secret', 'token', 'privateKey', 'signature'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }
        return $payload;
    }
}
