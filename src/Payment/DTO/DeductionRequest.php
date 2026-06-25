<?php

declare(strict_types=1);

namespace App\Payment\DTO;

final readonly class DeductionRequest
{
    public function __construct(
        public string $type,
        public int $amount,
        public string $currency,
        public array $options = [],
    ) {}
}
