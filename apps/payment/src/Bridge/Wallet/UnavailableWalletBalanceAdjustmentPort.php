<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wallet;

final class UnavailableWalletBalanceAdjustmentPort implements WalletBalanceAdjustmentPortInterface
{
    public function supports(string $currency, array $options): bool { return false; }
    public function apply(WalletBalanceAdjustmentRequest $request, array $options): ?WalletBalanceAdjustment { return null; }
    public function findApplied(string $invoiceId): ?WalletBalanceAdjustment { return null; }
    public function release(string $invoiceId, string $referenceId, string $reason): ?WalletBalanceAdjustment { return null; }
    public function refund(string $invoiceId, string $referenceId, string $reason): ?WalletBalanceAdjustment { return null; }
}
