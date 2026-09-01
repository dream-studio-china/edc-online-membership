<?php

declare(strict_types=1);

namespace App\Tests\Inventory\Command;

use App\Inventory\Command\PublishOutboxCommand;
use App\Inventory\Entity\InventoryOutboxMessage;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Tests\Integration\RecordingMessageBus;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationConfirmed;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationRejected;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationReleased;
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
            ->createQuery('DELETE FROM App\\Inventory\\Entity\\InventoryOutboxMessage message')
            ->execute();
        self::ensureKernelShutdown();
    }

    public function testPublishesEveryInventoryTopicWithNeutralCarriersAndCanonicalEnvelopes(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $topics = [
            'inventory.reservation.confirmed.v1' => InventoryReservationConfirmed::class,
            'inventory.reservation.rejected.v1' => InventoryReservationRejected::class,
            'inventory.reservation.released.v1' => InventoryReservationReleased::class,
        ];
        foreach ($topics as $topic => $carrier) {
            $entityManager->persist(new InventoryOutboxMessage(
                $topic,
                'inventory_reservation',
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
            $container->get(InventoryOutboxMessageRepository::class),
            $bus,
        );
        self::assertSame(0, $command->run(new ArrayInput([]), new NullOutput()));

        self::assertCount(3, $bus->messages);
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
