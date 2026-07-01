<?php

declare(strict_types=1);

namespace App\Payment\Service\Adjustment;

use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use App\Payment\Repository\DeductionRepository;
use App\Payment\Service\DeductionService;

final class WalletBalanceDeductionAdjustmentProvider implements PaymentAdjustmentProviderInterface
{
    public function __construct(
        private readonly DeductionService $deductionService,
        private readonly DeductionRepository $deductionRepository,
    ) {}

    public static function getName(): string
    {
        return Deduction::TYPE_WALLET_BALANCE;
    }

    public function supports(Invoice $invoice, string $payment, array $options): bool
    {
        return $this->deductionService->createRequestFromOptions($invoice, $options) !== null;
    }

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult
    {
        $deduction = $this->deductionService->applyFromOptions($context->invoice, $context->options);
        if (!$deduction instanceof Deduction) {
            throw new \RuntimeException('Wallet balance deduction request is missing.');
        }

        return self::resultFromDeduction($deduction);
    }

    public function applied(Invoice $invoice): array
    {
        $deduction = $this->deductionService->findApplied($invoice);
        return $deduction instanceof Deduction ? [self::resultFromDeduction($deduction)] : [];
    }

    public function release(PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        $deduction = $this->deductionFromResult($adjustment);
        $released = $this->deductionService->release($deduction->getInvoice(), $reason);

        return self::resultFromDeduction($released ?? $deduction);
    }

    public function refund(PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        $deduction = $this->deductionFromResult($adjustment);
        $refunded = $this->deductionService->refund($deduction->getInvoice(), $reason);

        return self::resultFromDeduction($refunded ?? $deduction);
    }

    private function deductionFromResult(PaymentAdjustmentResult $adjustment): Deduction
    {
        $deduction = $this->deductionRepository->findOneBy(['referenceId' => $adjustment->referenceId]);
        if (!$deduction instanceof Deduction) {
            throw new \RuntimeException(sprintf('Wallet balance deduction "%s" not found.', $adjustment->referenceId));
        }

        return $deduction;
    }

    private static function resultFromDeduction(Deduction $deduction): PaymentAdjustmentResult
    {
        return new PaymentAdjustmentResult(
            provider: self::getName(),
            amount: $deduction->getAmount(),
            currency: $deduction->getCurrency(),
            referenceId: $deduction->getReferenceId(),
            payload: array_filter([
                'deductionId' => $deduction->getUuid(),
                'transactionId' => $deduction->getWalletTransactionId(),
                'reversalTransactionId' => $deduction->getRefundTransactionId(),
                'status' => $deduction->getStatus(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
