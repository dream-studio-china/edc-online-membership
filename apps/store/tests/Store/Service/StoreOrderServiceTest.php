<?php

declare(strict_types=1);

namespace App\Tests\Store\Service;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Entity\OutboxMessage;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Service\OutboxService;
use App\Store\Service\StoreOrderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreOrderServiceTest extends TestCase
{
    public function testCreatesOneOrderForAnIdenticalTradeSnapshot(): void
    {
        $repository = $this->createMock(StoreOrderRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $stored = null;
        $repository->method('findOneByTradeOrderUuid')->willReturnCallback(function () use (&$stored): ?StoreOrder {
            return $stored;
        });
        $entityManager->method('getRepository')->with(StoreOrder::class)->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$stored): void {
            $stored = $entity;
        });

        $service = new StoreOrderService($this->createContainer($entityManager), $repository);
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $snapshot = [
            'orderUuid' => '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'store' => ['uuid' => $store->getUuid(), 'code' => 'xuhui', 'name' => 'Xuhui', 'channel' => 'mini_program'],
            'customerUserUuid' => '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57',
            'currency' => 'CNY',
            'totalAmount' => 12800,
            'items' => [],
            'delivery' => [],
            'placedAt' => '2026-07-24T12:00:00+00:00',
        ];

        $first = $service->createFromTradeOrderSnapshot($store, $snapshot);
        $second = $service->createFromTradeOrderSnapshot($store, $snapshot);

        self::assertSame($first, $second);
        self::assertSame($snapshot['items'], $first->getOrderSnapshot()['items']);
    }

    public function testAcceptRecordsAnOutboxMessage(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->method('getRepository')->with(StoreOrder::class)->willReturn($this->createMock(StoreOrderRepository::class));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $service = new StoreOrderService(
            $this->createContainer($entityManager),
            $this->createMock(StoreOrderRepository::class),
            new OutboxService($entityManager),
        );
        $order = $this->createOrder();

        self::assertSame($order, $service->accept($order, 'reservation-1'));
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $order->getOperationalStatus());
        self::assertSame('reservation-1', $order->getReservationId());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getAcceptedAt());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(OutboxMessage::class, $persisted[0]);
        self::assertSame('store.order.accepted.v1', $persisted[0]->getTopic());
        self::assertSame($order->getUuid(), $persisted[0]->getPayload()['storeOrderUuid']);
    }

    public function testRejectRecordsAnOutboxMessage(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->method('getRepository')->with(StoreOrder::class)->willReturn($this->createMock(StoreOrderRepository::class));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $service = new StoreOrderService(
            $this->createContainer($entityManager),
            $this->createMock(StoreOrderRepository::class),
            new OutboxService($entityManager),
        );
        $order = $this->createOrder();

        self::assertSame($order, $service->reject($order, 'out_of_stock', 'Inventory unavailable'));
        self::assertSame(StoreOrder::STATUS_REJECTED, $order->getOperationalStatus());
        self::assertSame('out_of_stock', $order->getRejectionCode());
        self::assertSame('Inventory unavailable', $order->getRejectionReason());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getRejectedAt());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(OutboxMessage::class, $persisted[0]);
        self::assertSame('store.order.rejected.v1', $persisted[0]->getTopic());
        self::assertSame('out_of_stock', $persisted[0]->getPayload()['reasonCode']);
    }

    public function testFulfillStoresFulfillmentData(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(StoreOrder::class)->willReturn($this->createMock(StoreOrderRepository::class));
        $service = new StoreOrderService($this->createContainer($entityManager), $this->createMock(StoreOrderRepository::class));
        $order = $this->createOrder();

        self::assertSame($order, $service->fulfill($order, ['trackingNumber' => 'TRACK-1']));
        self::assertSame(StoreOrder::STATUS_FULFILLED, $order->getOperationalStatus());
        self::assertSame(['trackingNumber' => 'TRACK-1'], $order->getFulfillmentData());
    }

    public function testAcceptRequiresAnOutboxService(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(StoreOrder::class)->willReturn($this->createMock(StoreOrderRepository::class));
        $service = new StoreOrderService($this->createContainer($entityManager), $this->createMock(StoreOrderRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store outbox is not configured.');
        $service->accept($this->createOrder());
    }

    private function createOrder(): StoreOrder
    {
        return new StoreOrder(
            new Store('xuhui', 'Xuhui', 'Asia/Shanghai'),
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'xuhui',
            'Xuhui',
            '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57',
            'CNY',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-24T12:00:00+00:00'],
        );
    }

    /** @return ContainerInterface */
    private function createContainer(EntityManagerInterface $entityManager): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'doctrine.orm.entity_manager' => $entityManager,
            'logger' => $this->createMock(LoggerInterface::class),
            'security.token_storage' => $this->createMock(TokenStorageInterface::class),
            default => null,
        });

        return $container;
    }
}
