<?php

declare(strict_types=1);

namespace App\Tests\Inventory\Message;

use App\Inventory\Message\InventoryReservationReleaseRequestedMessage;
use App\Inventory\Message\InventoryReservationReleasedMessage;
use App\Inventory\Message\InventoryReservationRequestedMessage;
use PHPUnit\Framework\TestCase;

final class InventoryMessageTest extends TestCase
{
    public function testMessagesExposeTheirEnvelope(): void
    {
        $envelope = ['eventId' => 'event'];
        self::assertSame($envelope, (new InventoryReservationRequestedMessage($envelope))->envelope);
        self::assertSame($envelope, (new InventoryReservationReleaseRequestedMessage($envelope))->envelope);
        self::assertSame($envelope, (new InventoryReservationReleasedMessage($envelope))->envelope);
    }
}
