<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Wallet\Entity\Voucher;

interface DepositProviderInterface
{
    public static function getName(): string;

    public function supports(string $voucherType): bool;

    /**
     * Verify the source backing the voucher is ready to fund the credit.
     * Must throw when the source is not in a state that allows the deposit
     * (e.g. an invoice that is not yet paid).
     *
     * @param array<string, mixed> $options
     */
    public function authorize(Voucher $voucher, array $options): void;

    /**
     * Hook for source-side handling when a voucher is reversed.
     *
     * @param array<string, mixed> $options
     */
    public function reverse(Voucher $voucher, string $reason, array $options = []): void;
}
