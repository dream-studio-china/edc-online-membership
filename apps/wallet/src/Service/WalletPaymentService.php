<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Wallet\Repository\WalletRepository;
use CrudPlatform\IntegrationContracts\Wallet\WalletTransferPortInterface;

class WalletPaymentService implements WalletTransferPortInterface
{
    public function __construct(
        private readonly WalletRepository $walletRepository,
        private readonly TransferServiceInterface $transferService,
    ) {}

    public function pay(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): TransferResult
    {
        return $this->transferOwnerResult($ownerUuid, $currency, $systemWalletId, $amount, $referenceId, $description, false);
    }

    public function refund(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): TransferResult
    {
        return $this->transferOwnerResult($ownerUuid, $currency, $systemWalletId, $amount, $referenceId, $description, true);
    }

    public function debitOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string
    {
        return $this->transferOwner($ownerUuid, $currency, $systemWalletId, $amount, $referenceId, $description, false);
    }

    public function creditOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string
    {
        return $this->transferOwner($ownerUuid, $currency, $systemWalletId, $amount, $referenceId, $description, true);
    }

    private function transferOwnerResult(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description, bool $refund): TransferResult
    {
        $wallet = $this->walletRepository->findByOwnerUuidAndCurrency($ownerUuid, $currency);
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', $currency));
        }

        return $refund
            ? $this->transferService->transfer($systemWalletId, $wallet->getId(), $amount, $referenceId, $description)
            : $this->transferService->transfer($wallet->getId(), $systemWalletId, $amount, $referenceId, $description);
    }

    private function transferOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description, bool $refund): string
    {
        $wallet = $this->walletRepository->findByOwnerUuidAndCurrency($ownerUuid, $currency);
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for owner.', $currency));
        }

        $transfer = $refund
            ? $this->transferService->transfer($systemWalletId, $wallet->getId(), $amount, $referenceId, $description)
            : $this->transferService->transfer($wallet->getId(), $systemWalletId, $amount, $referenceId, $description);

        return $transfer->transaction->getUuid();
    }
}
