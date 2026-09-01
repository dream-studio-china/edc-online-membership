<?php

declare(strict_types=1);

namespace App\Payment\DTO;


final readonly class CreateInvoiceRequest
{
    /** @param array<string, mixed> $extraData */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $scene,
        public int $amount,
        public string $currency = 'CNY',
        public ?string $payerUuid = null,
        public ?string $subject = null,
        public ?string $description = null,
        public array $extraData = [],
    ) {}
}
