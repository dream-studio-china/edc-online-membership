<?php

declare(strict_types=1);

namespace App\Tests\Store\MessageHandler;

use App\Inventory\Message\InventoryReservationConfirmedMessage;
use App\Inventory\Message\InventoryReservationRejectedMessage;
use App\Inventory\Message\InventoryReservationReleasedMessage;
use App\Store\Entity\StoreConsumedEvent;
use App\Store\MessageHandler\InventoryReservationConfirmedHandler;
use App\Store\MessageHandler\InventoryReservationRejectedHandler;
use App\Store\MessageHandler\InventoryReservationReleasedHandler;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Service\StoreOrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InventoryReservationOutcomeConcurrencyTest extends TestCase
{
    public function testConfirmationReturnsWhenEventIsConsumedInsideTheTransaction(): void
    {
        [$entityManager, $consumedRepository, $persistCount] = $this->concurrencyMocks();
        $storeOrderRepository = $this->createMock(StoreOrderRepository::class);
        $storeOrderService = $this->createMock(StoreOrderServiceInterface::class);

        $handler = new InventoryReservationConfirmedHandler($consumedRepository, $storeOrderRepository, $storeOrderService, $entityManager);
        $handler(new InventoryReservationConfirmedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000J1',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'confirmedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertSame(2, $persistCount[0]);
    }

    public function testRejectionReturnsWhenEventIsConsumedInsideTheTransaction(): void
    {
        [$entityManager, $consumedRepository, $persistCount] = $this->concurrencyMocks();
        $storeOrderRepository = $this->createMock(StoreOrderRepository::class);
        $storeOrderService = $this->createMock(StoreOrderServiceInterface::class);

        $handler = new InventoryReservationRejectedHandler($consumedRepository, $storeOrderRepository, $storeOrderService, $entityManager);
        $handler(new InventoryReservationRejectedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000J2',
            'type' => 'inventory.reservation.rejected',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'reasonCode' => 'X', 'reason' => 'y', 'rejectedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertSame(2, $persistCount[0]);
    }

    public function testReleaseReturnsWhenEventIsConsumedInsideTheTransaction(): void
    {
        [$entityManager, $consumedRepository, $persistCount] = $this->concurrencyMocks();

        $handler = new InventoryReservationReleasedHandler($consumedRepository, $entityManager);
        $handler(new InventoryReservationReleasedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000J3',
            'type' => 'inventory.reservation.released',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'releasedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertSame(2, $persistCount[0]);
    }

    /**
     * @return array{0: EntityManagerInterface, 1: StoreConsumedEventRepository, 2: array{0: int}}
     */
    private function concurrencyMocks(): array
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );

        $consumedEvent = new StoreConsumedEvent('consumed-id', 'any.topic.v1', 'aggregate', 'payload-hash');
        $consumedRepository = $this->createMock(StoreConsumedEventRepository::class);
        $calls = 0;
        $consumedRepository->method('findOneBy')->willReturnCallback(
            static function () use (&$calls, $consumedEvent): ?StoreConsumedEvent {
                return ++$calls > 1 ? $consumedEvent : null;
            },
        );

        $entityManager->expects(self::never())->method('persist');

        return [$entityManager, $consumedRepository, [&$calls]];
    }
}
