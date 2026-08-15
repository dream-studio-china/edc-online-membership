<?php

declare(strict_types=1);

namespace App\Wallet\Service\Withdraw;

use App\Wallet\Entity\Voucher;

final class ManualWithdrawProvider implements WithdrawProviderInterface
{
    public static function getName(): string
    {
        return Voucher::VOUCHER_TYPE_MANUAL;
    }

    public function supports(string $voucherType): bool
    {
        return $voucherType === Voucher::VOUCHER_TYPE_MANUAL;
    }

    public function authorize(Voucher $voucher, array $options): void
    {
        // Manual withdrawals are authorized by the admin action that created them.
    }

    public function reverse(Voucher $voucher, string $reason, array $options = []): void
    {
        // Manual withdrawal reversal is fully handled by WithdrawService.
    }
}
