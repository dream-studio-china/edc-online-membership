<?php

declare(strict_types=1);

namespace App\Tests\Trade\Integration;

use App\Store\Entity\OutboxMessage;
use App\Store\Repository\OutboxMessageRepository;
use App\Store\Service\StoreServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Repository\TradeStoreDirectoryRepository;
use App\Trade\Service\StoreContextResolverInterface;
use CrudPlatform\IntegrationContracts\Event\V1\StoreDirectoryUpserted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class StoreDirectoryProjectionTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\\Trade\\Entity\\TradeStoreDirectory directory')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\OutboxMessage message')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    public function testStoreChangePublishesAndProjectsDirectoryEntry(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('xuhui', 'Xuhui Store', 'Asia/Shanghai');

        $outbox = $container->get(OutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        self::assertSame('store.directory.upserted.v1', $outbox[0]->getTopic());

        $message = $outbox[0];
        self::assertInstanceOf(OutboxMessage::class, $message);
        $container->get(\App\Trade\MessageHandler\StoreDirectoryUpsertedHandler::class)(new StoreDirectoryUpserted([
            'eventId' => $message->getEventId(),
            'type' => 'store.directory.upserted',
            'version' => 1,
            'aggregateType' => 'store',
            'aggregateId' => $store->getUuid(),
            'occurredAt' => $message->getOccurredAt()->format(DATE_ATOM),
            'correlationId' => $message->getCorrelationId() ?? $message->getEventId(),
            'causationId' => null,
            'payload' => $message->getPayload(),
        ]));

        $directory = $container->get(TradeStoreDirectoryRepository::class)->findActiveByCode('xuhui');
        self::assertNotNull($directory);
        self::assertSame($store->getUuid(), $directory->getStoreUuid());

        $requestStack = $container->get(RequestStack::class);
        $requestStack->push(Request::create('/', 'POST', server: ['HTTP_X_STORE_CODE' => 'xuhui']));
        $context = $container->get(StoreContextResolverInterface::class)->resolve();
        $requestStack->pop();

        self::assertNotNull($context);
        self::assertSame($store->getUuid(), $context->storeUuid);
    }
}
