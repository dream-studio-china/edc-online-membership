<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Inventory\Entity\InventoryOutboxMessage;
use App\Store\Entity\OutboxMessage;
use App\Trade\Entity\TradeOutboxMessage;
use PHPUnit\Framework\TestCase;

final class OutboxCorrelationMetadataTest extends TestCase
{
    public function testNewOutboxMessagesStartNewCorrelationWhenNoContextIsProvided(): void
    {
        foreach ([
            new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'trade-order', []),
            new OutboxMessage('store.order.accepted.v1', 'store_order', 'store-order', []),
            new InventoryOutboxMessage('inventory.reservation.confirmed.v1', 'inventory_reservation', 'reservation', []),
        ] as $message) {
            self::assertSame($message->getEventId(), $message->getCorrelationId());
            self::assertNull($message->getCausationId());
        }
    }

    public function testOutboxMessagesPreserveInheritedCorrelationAndCausation(): void
    {
        $correlationId = '00000000-0000-4000-8000-000000000001';
        $causationId = '00000000-0000-4000-8000-000000000002';

        $trade = new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'trade-order', [], $correlationId, $causationId);
        $store = new OutboxMessage('store.order.accepted.v1', 'store_order', 'store-order', [], null, $correlationId, $causationId);
        $inventory = new InventoryOutboxMessage('inventory.reservation.confirmed.v1', 'inventory_reservation', 'reservation', [], null, $correlationId, $causationId);

        foreach ([$trade, $store, $inventory] as $message) {
            self::assertSame($correlationId, $message->getCorrelationId());
            self::assertSame($causationId, $message->getCausationId());
        }
    }
}
