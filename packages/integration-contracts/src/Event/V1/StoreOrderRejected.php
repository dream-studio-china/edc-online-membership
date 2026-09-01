<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class StoreOrderRejected extends Message
{
    public const string TYPE = 'store.order.rejected';
    public const int VERSION = 1;
    public const string TOPIC = 'store.order.rejected.v1';
}
