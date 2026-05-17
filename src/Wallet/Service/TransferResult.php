<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Wallet\Entity\WalletTransaction;

class TransferResult
{
    public function __construct(
        public readonly WalletTransaction $transaction,
        public readonly int $fromWalletBalanceAfter,
        public readonly int $toWalletBalanceAfter,
    ) {}
}
