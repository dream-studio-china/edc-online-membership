<?php

declare(strict_types=1);

namespace App\Tests\IntegrationContracts;

use App\Inventory\MessageHandler\InventoryReservationReleaseRequestedHandler;
use App\Inventory\MessageHandler\InventoryReservationRequestedHandler;
use App\Store\MessageHandler\InventoryReservationConfirmedHandler;
use App\Store\MessageHandler\InventoryReservationRejectedHandler;
use App\Store\MessageHandler\InventoryReservationReleasedHandler;
use App\Store\MessageHandler\TradeOrderCancelledHandler;
use App\Store\MessageHandler\TradeOrderCreatedHandler;
use App\Trade\MessageHandler\StoreOrderAcceptedHandler;
use App\Trade\MessageHandler\StoreOrderRejectedHandler;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationReleaseRequested;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationRequested;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationConfirmed;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationRejected;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationReleased;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderAccepted;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderRejected;
use CrudPlatform\IntegrationContracts\Event\V1\TradeOrderCancelled;
use CrudPlatform\IntegrationContracts\Event\V1\TradeOrderCreated;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class NeutralConsumerHandlerTest extends TestCase
{
    public function testEveryContractCarrierHasAnExplicitConsumerAdapter(): void
    {
        foreach ([
            TradeOrderCreated::class => TradeOrderCreatedHandler::class,
            TradeOrderCancelled::class => TradeOrderCancelledHandler::class,
            StoreOrderAccepted::class => StoreOrderAcceptedHandler::class,
            StoreOrderRejected::class => StoreOrderRejectedHandler::class,
            InventoryReservationRequested::class => InventoryReservationRequestedHandler::class,
            InventoryReservationReleaseRequested::class => InventoryReservationReleaseRequestedHandler::class,
            InventoryReservationConfirmed::class => InventoryReservationConfirmedHandler::class,
            InventoryReservationRejected::class => InventoryReservationRejectedHandler::class,
            InventoryReservationReleased::class => InventoryReservationReleasedHandler::class,
        ] as $carrier => $handler) {
            $method = new \ReflectionMethod($handler, 'handleContract');
            $attributes = $method->getAttributes(AsMessageHandler::class);

            self::assertCount(1, $attributes, $handler);
            self::assertSame($carrier, $attributes[0]->newInstance()->handles);
            self::assertSame($carrier, $method->getParameters()[0]->getType()?->getName());
        }
    }
}
