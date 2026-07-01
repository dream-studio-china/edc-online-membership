<?php

declare(strict_types=1);

namespace App\Wallet\DTO;

final readonly class WalletPaymentDeductionRequest
{
    public function __construct(
        public string $type,
        public int $amount,
        public string $currency,
        public array $options = [],
    ) {}
}
