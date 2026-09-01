<?php

declare(strict_types=1);

namespace App\Tests\Store\Command;

use App\Store\Command\PublishOutboxCommand;
use App\Store\Entity\OutboxMessage;
use App\Store\Repository\OutboxMessageRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Tests\Integration\RecordingMessageBus;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationReleaseRequested;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationRequested;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderAccepted;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderRejected;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class PublishOutboxCommandTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\\Store\\Entity\\OutboxMessage message')
            ->execute();
        self::ensureKernelShutdown();
    }

    public function testPublishesEveryStoreTopicWithNeutralCarriersAndCanonicalEnvelopes(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $topics = [
            'store.order.accepted.v1' => StoreOrderAccepted::class,
            'store.order.rejected.v1' => StoreOrderRejected::class,
            'inventory.reservation.requested.v1' => InventoryReservationRequested::class,
            'inventory.reservation.release.requested.v1' => InventoryReservationReleaseRequested::class,
        ];
        foreach ($topics as $topic => $carrier) {
            $entityManager->persist(new OutboxMessage(
                $topic,
                str_starts_with($topic, 'store.') ? 'store_order' : 'inventory_reservation',
                '00000000-0000-4000-8000-000000000010',
                [],
                null,
                '00000000-0000-4000-8000-000000000011',
                '00000000-0000-4000-8000-000000000012',
            ));
        }
        $entityManager->flush();

        $bus = new RecordingMessageBus();
        $command = new PublishOutboxCommand(
            $container->get(OutboxMessageRepository::class),
            $entityManager,
            $bus,
        );
        self::assertSame(0, $command->run(new ArrayInput([]), new NullOutput()));

        self::assertCount(4, $bus->messages);
        foreach (array_values($topics) as $index => $carrier) {
            self::assertInstanceOf($carrier, $bus->messages[$index]);
            self::assertEqualsCanonicalizing([
                'eventId', 'type', 'version', 'aggregateType', 'aggregateId',
                'occurredAt', 'correlationId', 'causationId', 'payload',
            ], array_keys($bus->messages[$index]->envelope));
            self::assertSame('00000000-0000-4000-8000-000000000011', $bus->messages[$index]->envelope['correlationId']);
            self::assertSame('00000000-0000-4000-8000-000000000012', $bus->messages[$index]->envelope['causationId']);
        }
    }
}
