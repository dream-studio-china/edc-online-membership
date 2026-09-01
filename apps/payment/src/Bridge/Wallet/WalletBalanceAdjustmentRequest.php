<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wallet;

final readonly class WalletBalanceAdjustmentRequest
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNo,
        public string $payerUuid,
        public int $amount,
        public string $currency,
        public string $subject,
    ) {}
}
