<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class PaymentInvoiceFailed extends Message
{
    public const string TYPE = 'payment.invoice.failed';
    public const int VERSION = 1;
    public const string TOPIC = 'payment.invoice.failed.v1';
}
