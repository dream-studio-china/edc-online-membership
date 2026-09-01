<?php

declare(strict_types=1);

namespace App\Payment\Service\Adjustment;

use App\Payment\Bridge\Wallet\WalletBalanceAdjustment;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustmentPortInterface;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustmentRequest;
use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;

final class WalletBalanceAdjustmentProvider implements PaymentAdjustmentProviderInterface
{
    public function __construct(private readonly WalletBalanceAdjustmentPortInterface $walletBalance) {}

    public static function getName(): string { return WalletBalanceAdjustmentPortInterface::NAME; }

    /** @param array<string, mixed> $options */
    public function supports(Invoice $invoice, string $payment, array $options): bool { return $this->walletBalance->supports($invoice->getCurrency(), $options); }

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult
    {
        $payerUuid = $context->invoice->getPayerUuid();
        if ($payerUuid === null) { throw new \RuntimeException('Invoice has no payer for wallet deduction.'); }
        $deduction = $this->walletBalance->apply(new WalletBalanceAdjustmentRequest($context->invoice->getUuid(), $context->invoice->getOutTradeNo(), $payerUuid, $context->invoice->getAmount(), $context->invoice->getCurrency(), $context->invoice->getSubject() ?? ('Deduction for invoice ' . $context->invoice->getOutTradeNo())), $context->options);
        if (!$deduction instanceof WalletBalanceAdjustment) { throw new \RuntimeException('Wallet balance deduction request is missing.'); }
        return $this->result($deduction);
    }

    public function applied(Invoice $invoice): array
    {
        $deduction = $this->walletBalance->findApplied($invoice->getUuid());
        return $deduction instanceof WalletBalanceAdjustment ? [$this->result($deduction)] : [];
    }

    public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return $this->result($this->walletBalance->release($invoice->getUuid(), $adjustment->referenceId, $reason) ?? $this->fallback($adjustment));
    }

    public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return $this->result($this->walletBalance->refund($invoice->getUuid(), $adjustment->referenceId, $reason) ?? $this->fallback($adjustment));
    }

    private function fallback(PaymentAdjustmentResult $adjustment): WalletBalanceAdjustment { return new WalletBalanceAdjustment($adjustment->amount, $adjustment->currency, $adjustment->referenceId, $adjustment->payload); }
    private function result(WalletBalanceAdjustment $deduction): PaymentAdjustmentResult { return new PaymentAdjustmentResult(self::getName(), $deduction->amount, $deduction->currency, $deduction->referenceId, $deduction->payload); }
}
