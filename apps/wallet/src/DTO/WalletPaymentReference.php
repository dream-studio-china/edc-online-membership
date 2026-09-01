<?php

declare(strict_types=1);

namespace App\Wallet\DTO;

final readonly class WalletPaymentReference
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNo,
        public int $payerId,
        public string $ownerUuid,
        public int $amount,
        public string $currency,
        public string $subject,
    ) {}
}
