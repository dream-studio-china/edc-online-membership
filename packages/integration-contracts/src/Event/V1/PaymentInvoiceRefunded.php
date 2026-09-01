<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class PaymentInvoiceRefunded extends Message
{
    public const string TYPE = 'payment.invoice.refunded';
    public const int VERSION = 1;
    public const string TOPIC = 'payment.invoice.refunded.v1';
}
