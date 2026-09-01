<?php

declare(strict_types=1);

namespace App\Store\Command;

use App\Store\Repository\OutboxMessageRepository;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationReleaseRequested;
use CrudPlatform\IntegrationContracts\Command\V1\InventoryReservationRequested;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderAccepted;
use CrudPlatform\IntegrationContracts\Event\V1\StoreDirectoryUpserted;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderRejected;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:store:outbox:publish', description: 'Publish pending Store integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxMessageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
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
                'store.order.accepted.v1' => new StoreOrderAccepted($envelope),
                'store.order.rejected.v1' => new StoreOrderRejected($envelope),
                'store.directory.upserted.v1' => new StoreDirectoryUpserted($envelope),
                'inventory.reservation.requested.v1' => new InventoryReservationRequested($envelope),
                'inventory.reservation.release.requested.v1' => new InventoryReservationReleaseRequested($envelope),
                default => null,
            };
            if ($busMessage === null) {
                $this->repository->defer($id, 'Unsupported Store outbox topic: ' . $message->getTopic(), new \DateTimeImmutable('+5 minutes'));
                continue;
            }
            try {
                $this->messageBus->dispatch($busMessage);
                $message->markPublished();
                ++$count;
            } catch (\Throwable $exception) {
                $this->repository->defer($id, $exception->getMessage(), new \DateTimeImmutable('+5 minutes'));
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Store outbox message(s).', $count));

        return Command::SUCCESS;
    }
}
