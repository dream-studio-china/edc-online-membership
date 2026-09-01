<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class StoreOrderAccepted extends Message
{
    public const string TYPE = 'store.order.accepted';
    public const int VERSION = 1;
    public const string TOPIC = 'store.order.accepted.v1';
}
