<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Wallet\DTO\WalletPaymentDeductionRequest;
use App\Wallet\DTO\WalletPaymentReference;
use App\Wallet\Entity\WalletPaymentDeduction;
use App\Wallet\Repository\WalletPaymentDeductionRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\TransferServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class WalletPaymentDeductionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WalletPaymentDeductionRepository $deductionRepository,
        private readonly WalletRepository $walletRepository,
        private readonly TransferServiceInterface $transferService,
        #[Autowire('%payment.system_wallet_id%')]
        private readonly ?int $systemWalletId = null,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function createRequestFromOptions(string $currency, array $options): ?WalletPaymentDeductionRequest
    {
        if (isset($options['walletAmount'])) {
            $amount = (int) $options['walletAmount'];
            if ($amount <= 0) {
                return null;
            }

            return new WalletPaymentDeductionRequest(
                WalletPaymentDeduction::TYPE_WALLET_BALANCE,
                $amount,
                (string) ($options['currency'] ?? $currency),
                $options,
            );
        }

        $deduction = $options['deduction'] ?? null;
        if (!is_array($deduction)) {
            return null;
        }

        return new WalletPaymentDeductionRequest(
            (string) ($deduction['type'] ?? WalletPaymentDeduction::TYPE_WALLET_BALANCE),
            (int) ($deduction['amount'] ?? 0),
            (string) ($deduction['currency'] ?? $currency),
            array_merge($options, $deduction['options'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function applyFromOptions(WalletPaymentReference $payment, array $options): ?WalletPaymentDeduction
    {
        $request = $this->createRequestFromOptions($payment->currency, $options);
        if ($request === null) {
            return null;
        }

        return $this->apply($payment, $request->amount, $request->currency, $request->options, $request->type);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function apply(WalletPaymentReference $payment, int $amount, string $currency, array $options = [], string $type = WalletPaymentDeduction::TYPE_WALLET_BALANCE): WalletPaymentDeduction
    {
        $this->validate($payment, $amount, $currency, $type);

        $existing = $this->deductionRepository->findWalletBalanceByInvoiceId($payment->invoiceId);
        if ($existing instanceof WalletPaymentDeduction) {
            if ($existing->getStatus() === WalletPaymentDeduction::STATUS_APPLIED) {
                return $existing;
            }
            throw new \RuntimeException(sprintf('Invoice wallet deduction already exists with status "%s".', $existing->getStatus()));
        }

        $systemWalletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet deduction.');
        }

        $wallet = $this->walletRepository->findByOwnerUuidAndCurrency($payment->ownerUuid, $currency);
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', strtoupper($currency)));
        }

        $referenceId = $options['deductionReferenceId'] ?? ('invoice-adjustment-wallet-balance-' . $payment->invoiceId);
        $deduction = new WalletPaymentDeduction($payment->invoiceId, $payment->invoiceNo, $payment->payerId, $wallet, $systemWalletId, $amount, $currency, $referenceId);
        $this->em->persist($deduction);

        try {
            $result = $this->transferService->transfer(
                $wallet->getId(),
                $systemWalletId,
                $amount,
                $referenceId,
                $payment->subject,
            );

            $deduction->markApplied($result->transaction->getUuid(), [
                'fromWalletId' => $wallet->getId(),
                'toWalletId' => $systemWalletId,
            ]);
            $this->em->flush();

            return $deduction;
        } catch (\Throwable $e) {
            $deduction->markFailed($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    public function release(string $invoiceId, string $reason): ?WalletPaymentDeduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoiceId($invoiceId);
        if (!$deduction instanceof WalletPaymentDeduction) {
            return null;
        }

        return $this->reverse($deduction, 'invoice-adjustment-wallet-balance-release-' . $invoiceId, $reason, false);
    }

    public function refund(string $invoiceId, string $reason): ?WalletPaymentDeduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoiceId($invoiceId);
        if (!$deduction instanceof WalletPaymentDeduction) {
            return null;
        }

        return $this->reverse($deduction, 'invoice-adjustment-wallet-balance-refund-' . $invoiceId, $reason, true);
    }

    public function sumAppliedAmount(string $invoiceId): int
    {
        $sum = 0;
        foreach ($this->deductionRepository->findAppliedDeductionsByInvoiceId($invoiceId) as $deduction) {
            $sum += $deduction->getAmount();
        }

        return $sum;
    }

    public function findApplied(string $invoiceId): ?WalletPaymentDeduction
    {
        return $this->deductionRepository->findAppliedByInvoiceId($invoiceId);
    }

    public function hasApplied(string $invoiceId): bool
    {
        return $this->findApplied($invoiceId) instanceof WalletPaymentDeduction;
    }

    private function validate(WalletPaymentReference $payment, int $amount, string $currency, string $type): void
    {
        if ($type !== WalletPaymentDeduction::TYPE_WALLET_BALANCE) {
            throw new \InvalidArgumentException(sprintf('Unsupported deduction type: %s', $type));
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive.');
        }
        if ($amount > $payment->amount) {
            throw new \InvalidArgumentException('Deduction amount cannot exceed invoice amount.');
        }
        if (strtoupper($currency) !== strtoupper($payment->currency)) {
            throw new \InvalidArgumentException('Deduction currency must match invoice currency.');
        }
    }

    private function reverse(WalletPaymentDeduction $deduction, string $referenceId, string $reason, bool $refund): WalletPaymentDeduction
    {
        $walletId = $deduction->getWallet()->getId();
        \assert($walletId !== null);

        $result = $this->transferService->transfer(
            $deduction->getSystemWalletId(),
            $walletId,
            $deduction->getAmount(),
            $referenceId,
            $reason,
        );

        if ($refund) {
            $deduction->markRefunded($result->transaction->getUuid(), $reason);
        } else {
            $deduction->markReleased($result->transaction->getUuid(), $reason);
        }
        $this->em->flush();

        return $deduction;
    }
}
