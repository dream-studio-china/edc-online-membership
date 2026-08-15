<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Wallet\Entity\Voucher;

final class ManualDepositProvider implements DepositProviderInterface
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
        // Manual deposits are authorized by the admin action that created them.
    }

    public function reverse(Voucher $voucher, string $reason, array $options = []): void
    {
        // Manual deposit reversal is fully handled by DepositService.
    }
}
