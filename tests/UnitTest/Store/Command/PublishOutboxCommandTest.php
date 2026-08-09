<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Command;

use App\Inventory\Message\InventoryReservationReleaseRequestedMessage;
use App\Inventory\Message\InventoryReservationRequestedMessage;
use App\Store\Command\PublishOutboxCommand;
use App\Store\Entity\StoreOutboxMessage;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\Message\StoreOrderRejectedMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class PublishOutboxCommandTest extends TestCase
{
    private CommandTester $tester;

    public function testExecuteWithNoUnpublishedMessages(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $repository->expects(self::once())->method('findUnpublished')->willReturn([]);
        $bus->expects(self::never())->method('dispatch');
        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Store outbox message(s).', $this->display());
    }

    public function testExecuteSkipsMessageWithNullId(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $message = new StoreOutboxMessage('store.order.accepted.v1', 'store_order', 'order-11111111-1111-4111-8111-111111111111', ['orderUuid' => 'order-11111111-1111-4111-8111-111111111111']);

        $repository->expects(self::once())->method('findUnpublished')->willReturn([$message]);
        $repository->expects(self::never())->method('claim');
        $bus->expects(self::never())->method('dispatch');
        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Store outbox message(s).', $this->display());
        self::assertFalse($message->isPublished());
    }

    public function testExecuteSkipsMessageWhenClaimFails(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $message = $this->outboxMessage(11, 'store.order.accepted.v1');

        $repository->expects(self::once())->method('findUnpublished')->willReturn([$message]);
        $repository->expects(self::once())
            ->method('claim')
            ->with(11, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(false);
        $bus->expects(self::never())->method('dispatch');
        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Store outbox message(s).', $this->display());
        self::assertFalse($message->isPublished());
    }

    public function testExecuteDefersUnsupportedTopic(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $message = $this->outboxMessage(12, 'unknown.future.topic.v1');

        $repository->expects(self::once())->method('findUnpublished')->willReturn([$message]);
        $repository->expects(self::once())
            ->method('claim')
            ->with(12, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(true);
        $bus->expects(self::never())->method('dispatch');
        $repository->expects(self::once())
            ->method('defer')
            ->with(12, 'Unsupported Store outbox topic: unknown.future.topic.v1', self::isInstanceOf(\DateTimeImmutable::class));
        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Store outbox message(s).', $this->display());
        self::assertFalse($message->isPublished());
    }

    public function testExecuteDispatchesAllSupportedTopicsAndMarksPublished(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $messages = [
            $this->outboxMessage(21, 'store.order.accepted.v1'),
            $this->outboxMessage(22, 'store.order.rejected.v1'),
            $this->outboxMessage(23, 'inventory.reservation.requested.v1'),
            $this->outboxMessage(24, 'inventory.reservation.release.requested.v1'),
        ];

        $repository->expects(self::once())->method('findUnpublished')->willReturn($messages);
        $claimed = [];
        $repository->expects(self::exactly(4))
            ->method('claim')
            ->with(self::isInt(), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnCallback(function (int $id, \DateTimeImmutable $until) use (&$claimed): bool {
                $claimed[] = [$id, $until];

                return true;
            });

        $dispatched = [];
        $bus->expects(self::exactly(4))
            ->method('dispatch')
            ->with(self::callback(static function (object $busMessage) use (&$dispatched): bool {
                $dispatched[] = $busMessage;

                return true;
            }))
            ->willReturnCallback(static function (object $busMessage) use (&$dispatched): \Symfony\Component\Messenger\Envelope {
                return new \Symfony\Component\Messenger\Envelope($busMessage);
            });

        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 4 Store outbox message(s).', $this->display());
        self::assertCount(4, $dispatched);
        self::assertInstanceOf(StoreOrderAcceptedMessage::class, $dispatched[0]);
        self::assertInstanceOf(StoreOrderRejectedMessage::class, $dispatched[1]);
        self::assertInstanceOf(InventoryReservationRequestedMessage::class, $dispatched[2]);
        self::assertInstanceOf(InventoryReservationReleaseRequestedMessage::class, $dispatched[3]);

        foreach ($messages as $index => $message) {
            self::assertTrue($message->isPublished(), 'Message ' . $index . ' should be marked published');
            $envelope = $dispatched[$index]->envelope;
            self::assertSame($message->getEventId(), $envelope['eventId']);
            self::assertSame(str_replace('.v1', '', $message->getTopic()), $envelope['type']);
            self::assertSame(1, $envelope['version']);
            self::assertSame($message->getAggregateId(), $envelope['aggregateId']);
            self::assertSame($message->getPayload(), $envelope['payload']);
        }
    }

    public function testExecuteDefersWhenDispatchThrows(): void
    {
        $repository = $this->createMock(StoreOutboxMessageRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $message = $this->outboxMessage(31, 'store.order.accepted.v1');

        $repository->expects(self::once())->method('findUnpublished')->willReturn([$message]);
        $repository->expects(self::once())
            ->method('claim')
            ->with(31, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(true);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Message bus is down'));
        $repository->expects(self::once())
            ->method('defer')
            ->with(31, 'Message bus is down', self::isInstanceOf(\DateTimeImmutable::class));
        $entityManager->expects(self::once())->method('flush');

        $exitCode = $this->runCommand($repository, $entityManager, $bus);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Published 0 Store outbox message(s).', $this->display());
        self::assertFalse($message->isPublished());
    }

    private function outboxMessage(int $id, string $topic): StoreOutboxMessage
    {
        $message = new StoreOutboxMessage(
            $topic,
            'store_order',
            'order-11111111-1111-4111-8111-111111111111',
            ['orderUuid' => 'order-11111111-1111-4111-8111-111111111111'],
        );
        (new \ReflectionProperty(StoreOutboxMessage::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function runCommand(
        StoreOutboxMessageRepository $repository,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ): int {
        $command = new PublishOutboxCommand($repository, $entityManager, $bus);
        $this->tester = new CommandTester($command);

        return $this->tester->execute([]);
    }

    private function display(): string
    {
        return $this->tester->getDisplay();
    }
}
