<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wallet;

interface WalletBalanceAdjustmentPortInterface
{
    public const NAME = 'wallet_balance';

    /** @param array<string, mixed> $options */
    public function supports(string $currency, array $options): bool;

    /** @param array<string, mixed> $options */
    public function apply(WalletBalanceAdjustmentRequest $request, array $options): ?WalletBalanceAdjustment;

    public function findApplied(string $invoiceId): ?WalletBalanceAdjustment;

    public function release(string $invoiceId, string $referenceId, string $reason): ?WalletBalanceAdjustment;

    public function refund(string $invoiceId, string $referenceId, string $reason): ?WalletBalanceAdjustment;
}
