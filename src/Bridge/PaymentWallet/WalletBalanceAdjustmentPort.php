<?php

declare(strict_types=1);

namespace App\Bridge\PaymentWallet;

use App\Core\Security\IdentityUserIdResolverInterface;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustment;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustmentPortInterface;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustmentRequest;
use App\Wallet\DTO\WalletPaymentReference;
use App\Wallet\Entity\WalletPaymentDeduction;
use App\Wallet\Repository\WalletPaymentDeductionRepository;
use App\Wallet\Service\Payment\WalletPaymentDeductionService;

final class WalletBalanceAdjustmentPort implements WalletBalanceAdjustmentPortInterface
{
    public function __construct(
        private readonly WalletPaymentDeductionService $deductionService,
        private readonly WalletPaymentDeductionRepository $deductionRepository,
        private readonly IdentityUserIdResolverInterface $identityUserIdResolver,
    ) {}

    public function supports(string $currency, array $options): bool
    {
        return $this->deductionService->createRequestFromOptions($currency, $options) !== null;
    }

    public function apply(WalletBalanceAdjustmentRequest $request, array $options): ?WalletBalanceAdjustment
    {
        $payerId = $this->identityUserIdResolver->resolveIdentityUserId($request->payerUuid);
        if ($payerId === null) {
            throw new \RuntimeException('Invoice has no payer for wallet deduction.');
        }
        $deduction = $this->deductionService->applyFromOptions(new WalletPaymentReference($request->invoiceId, $request->invoiceNo, $payerId, $request->payerUuid, $request->amount, $request->currency, $request->subject), $options);
        return $deduction instanceof WalletPaymentDeduction ? $this->adjustment($deduction) : null;
    }

    public function findApplied(string $invoiceId): ?WalletBalanceAdjustment
    {
        $deduction = $this->deductionService->findApplied($invoiceId);
        return $deduction instanceof WalletPaymentDeduction ? $this->adjustment($deduction) : null;
    }

    public function release(string $invoiceId, string $referenceId, string $reason): WalletBalanceAdjustment
    {
        $deduction = $this->deductionService->release($invoiceId, $reason) ?? $this->deduction($referenceId);
        return $this->adjustment($deduction);
    }

    public function refund(string $invoiceId, string $referenceId, string $reason): WalletBalanceAdjustment
    {
        $deduction = $this->deductionService->refund($invoiceId, $reason) ?? $this->deduction($referenceId);
        return $this->adjustment($deduction);
    }

    private function deduction(string $referenceId): WalletPaymentDeduction
    {
        $deduction = $this->deductionRepository->findOneBy(['referenceId' => $referenceId]);
        if (!$deduction instanceof WalletPaymentDeduction) {
            throw new \RuntimeException(sprintf('Wallet balance deduction "%s" not found.', $referenceId));
        }
        return $deduction;
    }

    private function adjustment(WalletPaymentDeduction $deduction): WalletBalanceAdjustment
    {
        return new WalletBalanceAdjustment($deduction->getAmount(), $deduction->getCurrency(), $deduction->getReferenceId(), array_filter([
            'deductionId' => $deduction->getUuid(),
            'transactionId' => $deduction->getWalletTransactionId(),
            'reversalTransactionId' => $deduction->getReversalTransactionId(),
            'status' => $deduction->getStatus(),
        ], static fn (mixed $value): bool => $value !== null));
    }
}
