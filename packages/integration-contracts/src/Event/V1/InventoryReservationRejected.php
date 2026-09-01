<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class InventoryReservationRejected extends Message
{
    public const string TYPE = 'inventory.reservation.rejected';
    public const int VERSION = 1;
    public const string TOPIC = 'inventory.reservation.rejected.v1';
}
