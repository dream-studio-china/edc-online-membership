<?php

declare(strict_types=1);

namespace App\Payment\DTO;

use App\Payment\Entity\Invoice;

final readonly class PaymentAdjustmentContext
{
    public function __construct(
        public Invoice $invoice,
        public string $payment,
        public int $invoiceAmount,
        public string $currency,
        public array $options = [],
    ) {}
}
