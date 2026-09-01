<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wallet;

final readonly class WalletBalanceAdjustment
{
    public function __construct(
        public int $amount,
        public string $currency,
        public string $referenceId,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}
}
