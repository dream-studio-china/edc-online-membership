<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;

interface TransferServiceInterface
{
    /**
     * Transfer amount from source wallet to target wallet.
     * Transactions are atomic: either both debit and credit happen, or neither.
     * Uses pessimistic locking to prevent race conditions.
     *
     * @return TransferResult containing the transaction record
     * @throws InsufficientFundsException
     * @throws WalletFrozenException
     * @throws SameWalletTransferException
     */
    public function transfer(int $fromWalletId, int $toWalletId, int $amount, ?string $referenceId = null, ?string $description = null): TransferResult;

    /**
     * Inject funds into a wallet from the system (no source wallet).
     * Creates a TYPE_DEPOSIT transaction for audit trail.
     *
     * @return TransferResult containing the transaction record
     * @throws WalletFrozenException
     */
    public function deposit(int $toWalletId, int $amount, ?string $referenceId = null, ?string $description = null): TransferResult;
}
