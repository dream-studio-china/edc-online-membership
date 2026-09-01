<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\Message;

final readonly class TradeOrderCancelled extends Message
{
    public const string TYPE = 'trade.order.cancelled';
    public const int VERSION = 1;
    public const string TOPIC = 'trade.order.cancelled.v1';
}
