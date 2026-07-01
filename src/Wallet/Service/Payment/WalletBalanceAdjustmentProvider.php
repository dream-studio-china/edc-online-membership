<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface;
use App\Wallet\Entity\WalletPaymentDeduction;
use App\Wallet\Repository\WalletPaymentDeductionRepository;

final class WalletBalanceAdjustmentProvider implements PaymentAdjustmentProviderInterface
{
    public function __construct(
        private readonly WalletPaymentDeductionService $deductionService,
        private readonly WalletPaymentDeductionRepository $deductionRepository,
    ) {}

    public static function getName(): string
    {
        return WalletPaymentDeduction::TYPE_WALLET_BALANCE;
    }

    public function supports(Invoice $invoice, string $payment, array $options): bool
    {
        return $this->deductionService->createRequestFromOptions($invoice, $options) !== null;
    }

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult
    {
        $deduction = $this->deductionService->applyFromOptions($context->invoice, $context->options);
        if (!$deduction instanceof WalletPaymentDeduction) {
            throw new \RuntimeException('Wallet balance deduction request is missing.');
        }

        return self::resultFromDeduction($deduction);
    }

    public function applied(Invoice $invoice): array
    {
        $deduction = $this->deductionService->findApplied($invoice);
        return $deduction instanceof WalletPaymentDeduction ? [self::resultFromDeduction($deduction)] : [];
    }

    public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        $deduction = $this->deductionFromResult($adjustment);
        $released = $this->deductionService->release($invoice, $reason);

        return self::resultFromDeduction($released ?? $deduction);
    }

    public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        $deduction = $this->deductionFromResult($adjustment);
        $refunded = $this->deductionService->refund($invoice, $reason);

        return self::resultFromDeduction($refunded ?? $deduction);
    }

    private function deductionFromResult(PaymentAdjustmentResult $adjustment): WalletPaymentDeduction
    {
        $deduction = $this->deductionRepository->findOneBy(['referenceId' => $adjustment->referenceId]);
        if (!$deduction instanceof WalletPaymentDeduction) {
            throw new \RuntimeException(sprintf('Wallet balance deduction "%s" not found.', $adjustment->referenceId));
        }

        return $deduction;
    }

    private static function resultFromDeduction(WalletPaymentDeduction $deduction): PaymentAdjustmentResult
    {
        return new PaymentAdjustmentResult(
            provider: self::getName(),
            amount: $deduction->getAmount(),
            currency: $deduction->getCurrency(),
            referenceId: $deduction->getReferenceId(),
            payload: array_filter([
                'deductionId' => $deduction->getUuid(),
                'transactionId' => $deduction->getWalletTransactionId(),
                'reversalTransactionId' => $deduction->getReversalTransactionId(),
                'status' => $deduction->getStatus(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
