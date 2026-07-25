<?php

declare(strict_types=1);

namespace App\Trade\Command;

use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:trade:outbox:publish', description: 'Publish pending Trade integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly TradeOutboxMessageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->repository->findUnpublished() as $message) {
            if ($message->getTopic() !== 'trade.order.created.v1') {
                continue;
            }
            $this->messageBus->dispatch(new TradeOrderCreatedMessage([
                'eventId' => $message->getEventId(),
                'type' => 'trade.order.created',
                'version' => 1,
                'occurredAt' => $message->getOccurredAt()->format(DATE_ATOM),
                'aggregateType' => 'trade_order',
                'aggregateId' => $message->getAggregateId(),
                'correlationId' => $message->getAggregateId(),
                'causationId' => null,
                'payload' => $message->getPayload(),
            ]));
            $message->markPublished();
            ++$count;
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Trade outbox message(s).', $count));

        return Command::SUCCESS;
    }
}
