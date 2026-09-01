<?php

declare(strict_types=1);

namespace App\Tests\Trade\Command;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Tests\Integration\RecordingMessageBus;
use App\Trade\Command\PublishOutboxCommand;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use CrudPlatform\IntegrationContracts\Event\V1\TradeOrderCreated;
use CrudPlatform\IntegrationContracts\Event\V1\TradeOrderCancelled;

final class PublishOutboxCommandTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\\Trade\\Entity\\TradeOutboxMessage message')
            ->execute();
        self::ensureKernelShutdown();
    }

    public function testPublishesTradeTopicsWithNeutralCarriersAndCanonicalEnvelopes(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $created = new TradeOutboxMessage(
            'trade.order.created.v1',
            'trade_order',
            '00000000-0000-4000-8000-000000000010',
            ['orderUuid' => '00000000-0000-4000-8000-000000000010'],
            '00000000-0000-4000-8000-000000000011',
            '00000000-0000-4000-8000-000000000012',
        );
        $cancelled = new TradeOutboxMessage(
            'trade.order.cancelled.v1',
            'trade_order',
            '00000000-0000-4000-8000-000000000020',
            ['orderUuid' => '00000000-0000-4000-8000-000000000020'],
            '00000000-0000-4000-8000-000000000021',
            '00000000-0000-4000-8000-000000000022',
        );
        $entityManager->persist($created);
        $entityManager->persist($cancelled);
        $entityManager->flush();

        $bus = new RecordingMessageBus();

        $command = new PublishOutboxCommand(
            $container->get(TradeOutboxMessageRepository::class),
            $entityManager,
            $bus,
        );
        self::assertSame(0, $command->run(new ArrayInput([]), new NullOutput()));

        self::assertCount(2, $bus->messages);
        self::assertInstanceOf(TradeOrderCreated::class, $bus->messages[0]);
        self::assertInstanceOf(TradeOrderCancelled::class, $bus->messages[1]);
        self::assertSame('00000000-0000-4000-8000-000000000011', $bus->messages[0]->envelope['correlationId']);
        self::assertSame('00000000-0000-4000-8000-000000000012', $bus->messages[0]->envelope['causationId']);
        self::assertSame('00000000-0000-4000-8000-000000000021', $bus->messages[1]->envelope['correlationId']);
        self::assertSame('00000000-0000-4000-8000-000000000022', $bus->messages[1]->envelope['causationId']);
        foreach ($bus->messages as $message) {
            self::assertEqualsCanonicalizing([
                'eventId', 'type', 'version', 'aggregateType', 'aggregateId',
                'occurredAt', 'correlationId', 'causationId', 'payload',
            ], array_keys($message->envelope));
        }
    }
}
