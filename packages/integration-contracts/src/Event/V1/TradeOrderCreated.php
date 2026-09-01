<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class TradeOrderCreated extends Message
{
    public const string TYPE = 'trade.order.created';
    public const int VERSION = 1;
    public const string TOPIC = 'trade.order.created.v1';
}
