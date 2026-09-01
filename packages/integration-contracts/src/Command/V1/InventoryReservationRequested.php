<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Command\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class InventoryReservationRequested extends Message
{
    public const string TYPE = 'inventory.reservation.requested';
    public const int VERSION = 1;
    public const string TOPIC = 'inventory.reservation.requested.v1';
}
