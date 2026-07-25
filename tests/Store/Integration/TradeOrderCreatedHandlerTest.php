<?php

declare(strict_types=1);

namespace App\Tests\Store\Integration;

use App\Store\Entity\Store;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Service\StoreServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Message\TradeOrderCreatedMessage;
use Doctrine\ORM\EntityManagerInterface;

final class TradeOrderCreatedHandlerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOutboxMessage message')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreConsumedEvent event')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOrder storeOrder')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    public function testConsumesTradeOrderOnceAndPublishesAcceptance(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $stores = $container->get(StoreServiceInterface::class);
        $store = $stores->createStore('xuhui', 'Xuhui Store', 'Asia/Shanghai');
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $message = new TradeOrderCreatedMessage([
            'eventId' => 'a0d04d82-fb27-4b2d-8c54-2f896d4c6533',
            'payload' => [
                'orderUuid' => '96a1a1b2-4f86-44ff-94cb-41a1411ad0d8',
                'store' => [
                    'uuid' => $store->getUuid(),
                    'code' => $store->getCode(),
                    'name' => $store->getName(),
                    'channel' => 'api',
                ],
                'customerUserUuid' => null,
                'currency' => 'CNY',
                'totalAmount' => 12800,
                'items' => [],
                'delivery' => [],
                'placedAt' => '2026-07-25T00:00:00+00:00',
            ],
        ]);

        $handler($message);
        $handler($message);

        $orders = $container->get(StoreOrderRepository::class);
        $storeOrder = $orders->findOneByTradeOrderUuid('96a1a1b2-4f86-44ff-94cb-41a1411ad0d8');
        self::assertNotNull($storeOrder);
        self::assertSame('accepted', $storeOrder->getOperationalStatus());

        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        self::assertSame('store.order.accepted.v1', $outbox[0]->getTopic());
        self::assertSame($storeOrder->getTradeOrderUuid(), $outbox[0]->getPayload()['orderUuid']);
    }

    public function testRejectsAnOrderForAnUnavailableStoreAndConsumesTheEvent(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);
        $message = new TradeOrderCreatedMessage([
            'eventId' => 'e2ac7552-a853-473c-a90a-899fb93d28f',
            'payload' => [
                'orderUuid' => 'e60b13bd-8e46-453f-b6b3-4b3bc59259b4',
                'store' => ['uuid' => 'c4843f0c-9ab8-4d5b-adc2-02c0f497a937'],
            ],
        ]);

        $handler($message);
        $handler($message);

        self::assertNull($container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid('e60b13bd-8e46-453f-b6b3-4b3bc59259b4'));
        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        self::assertSame('store.order.rejected.v1', $outbox[0]->getTopic());
        self::assertSame('STORE_UNAVAILABLE', $outbox[0]->getPayload()['reasonCode']);
    }

    public function testRejectsMalformedTradeOrderEnvelopes(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.created.v1 envelope.');
        $handler(new TradeOrderCreatedMessage(['eventId' => 'event-id', 'payload' => 'invalid']));
    }
}
