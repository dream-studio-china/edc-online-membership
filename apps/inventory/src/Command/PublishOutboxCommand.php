<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Repository\InventoryOutboxMessageRepository;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationConfirmed;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationRejected;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationReleased;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:inventory:outbox:publish', description: 'Publish pending Inventory integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly InventoryOutboxMessageRepository $repository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = 0;
        foreach ($this->repository->findUnpublished() as $message) {
            $id = $message->getId();
            if ($id === null || !$this->repository->claim($id, new \DateTimeImmutable('+1 minute'))) {
                continue;
            }
            $envelope = [
                'eventId' => $message->getEventId(),
                'type' => str_replace('.v1', '', $message->getTopic()),
                'version' => 1,
                'aggregateType' => $message->getAggregateType(),
                'aggregateId' => $message->getAggregateId(),
                'occurredAt' => $message->getOccurredAt()->format(DATE_ATOM),
                'correlationId' => $message->getCorrelationId() ?? $message->getEventId(),
                'causationId' => $message->getCausationId(),
                'payload' => $message->getPayload(),
            ];
            $busMessage = match ($message->getTopic()) {
                'inventory.reservation.confirmed.v1' => new InventoryReservationConfirmed($envelope),
                'inventory.reservation.rejected.v1' => new InventoryReservationRejected($envelope),
                'inventory.reservation.released.v1' => new InventoryReservationReleased($envelope),
                default => null,
            };
            if ($busMessage === null) {
                $this->repository->recordAttempt(
                    $id,
                    'Unsupported Inventory outbox topic: ' . $message->getTopic(),
                    new \DateTimeImmutable('+5 minutes'),
                );
                continue;
            }

            try {
                $this->messageBus->dispatch($busMessage);
                $this->repository->markPublished($id);
                ++$published;
            } catch (\Throwable $exception) {
                $this->repository->recordAttempt(
                    $id,
                    $exception->getMessage(),
                    new \DateTimeImmutable('+5 minutes'),
                );
            }
        }
        $output->writeln(sprintf('Published %d Inventory outbox message(s).', $published));

        return Command::SUCCESS;
    }
}
