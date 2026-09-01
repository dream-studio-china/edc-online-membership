<?php

declare(strict_types=1);

namespace App\Tests\Store\Integration;

use App\Store\Repository\OutboxMessageRepository;
use App\Store\Service\StoreServiceInterface;
use CrudPlatform\IntegrationContracts\Event\V1\StoreDirectoryUpserted;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Trade\Entity\Product;
use App\Trade\Entity\Specification;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StoreScopedOrderFlowTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testStoreScopedOrderWaitsForAcceptanceThenBecomesConfirmable(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $store = $container->get(StoreServiceInterface::class)->createStore('xuhui', 'Xuhui Store', 'Asia/Shanghai');
        $directoryEvent = $container->get(OutboxMessageRepository::class)->findUnpublished()[0];
        $container->get(\App\Trade\MessageHandler\StoreDirectoryUpsertedHandler::class)(new StoreDirectoryUpserted([
            'eventId' => $directoryEvent->getEventId(),
            'type' => 'store.directory.upserted',
            'version' => 1,
            'aggregateType' => 'store',
            'aggregateId' => $store->getUuid(),
            'occurredAt' => $directoryEvent->getOccurredAt()->format(DATE_ATOM),
            'correlationId' => $directoryEvent->getCorrelationId() ?? $directoryEvent->getEventId(),
            'causationId' => null,
            'payload' => $directoryEvent->getPayload(),
        ]));

        $product = new Product();
        $product->setName('Tea');
        $specification = new Specification();
        $specification->setName('Large')->setPrice(6400);
        $product->addSpecification($specification);
        $entityManager->persist($product);
        $entityManager->flush();

        $client->setServerParameter('HTTP_X_STORE_CODE', $store->getCode());
        $client->request('POST', '/api/v1/app/orders', [], [], [], json_encode([
            'items' => [['specificationId' => $specification->getId(), 'quantity' => 2]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);
        $response = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $orderUuid = $response['data']['uuid'];
        $order = $entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('awaiting_store_acceptance', $order->getStatus());

        $tradeOutbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $tradeOutbox);
        $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class)(new TradeOrderCreatedMessage([
            'eventId' => $tradeOutbox[0]->getEventId(),
            'payload' => $tradeOutbox[0]->getPayload(),
        ]));

        $storeOutbox = array_values(array_filter(
            $container->get(OutboxMessageRepository::class)->findUnpublished(),
            static fn ($message): bool => $message->getTopic() !== 'store.directory.upserted.v1',
        ));
        self::assertCount(1, $storeOutbox);
        $container->get(\App\Trade\MessageHandler\StoreOrderAcceptedHandler::class)(new StoreOrderAcceptedMessage([
            'eventId' => $storeOutbox[0]->getEventId(),
            'payload' => $storeOutbox[0]->getPayload(),
        ]));

        $entityManager->clear();
        $acceptedOrder = $entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $acceptedOrder);
        self::assertSame('store_accepted', $acceptedOrder->getStatus());
    }
}
