<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class InventoryReservationConfirmed extends Message
{
    public const string TYPE = 'inventory.reservation.confirmed';
    public const int VERSION = 1;
    public const string TOPIC = 'inventory.reservation.confirmed.v1';
}
