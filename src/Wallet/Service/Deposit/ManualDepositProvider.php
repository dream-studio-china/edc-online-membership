<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Wallet\Entity\WalletVoucher;

final class ManualDepositProvider implements WalletDepositProviderInterface
{
    public static function getName(): string
    {
        return WalletVoucher::VOUCHER_TYPE_MANUAL;
    }

    public function supports(string $voucherType): bool
    {
        return $voucherType === WalletVoucher::VOUCHER_TYPE_MANUAL;
    }

    public function authorize(WalletVoucher $voucher, array $options): void
    {
        // Manual deposits are authorized by the admin action that created them.
    }

    public function reverse(WalletVoucher $voucher, string $reason, array $options = []): void
    {
        // Manual deposit reversal is fully handled by WalletDepositService.
    }
}
