<?php

declare(strict_types=1);

namespace App\Store\Command;

use App\Store\Repository\StoreOutboxMessageRepository;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\Message\StoreOrderRejectedMessage;
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
        private readonly StoreOutboxMessageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->repository->findUnpublished() as $message) {
            $envelope = [
                'eventId' => $message->getEventId(),
                'type' => str_replace('.v1', '', $message->getTopic()),
                'version' => 1,
                'aggregateId' => $message->getAggregateId(),
                'payload' => $message->getPayload(),
            ];
            $busMessage = match ($message->getTopic()) {
                'store.order.accepted.v1' => new StoreOrderAcceptedMessage($envelope),
                'store.order.rejected.v1' => new StoreOrderRejectedMessage($envelope),
                default => null,
            };
            if ($busMessage === null) {
                continue;
            }
            $this->messageBus->dispatch($busMessage);
            $message->markPublished();
            ++$count;
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Store outbox message(s).', $count));

        return Command::SUCCESS;
    }
}
