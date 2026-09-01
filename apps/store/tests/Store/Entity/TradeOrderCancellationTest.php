<?php

declare(strict_types=1);

namespace App\Tests\Store\Entity;

use App\Store\Entity\TradeOrderCancellation;
use PHPUnit\Framework\TestCase;

final class TradeOrderCancellationTest extends TestCase
{
    public function testItRetainsTheCancelledTradeOrderDetails(): void
    {
        $cancelledAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $cancellation = new TradeOrderCancellation('trade-order-uuid', 'store-uuid', $cancelledAt);

        self::assertSame('trade-order-uuid', $cancellation->getTradeOrderUuid());
        self::assertSame('store-uuid', $cancellation->getStoreUuid());
        self::assertSame($cancelledAt, $cancellation->getCancelledAt());
    }
}
