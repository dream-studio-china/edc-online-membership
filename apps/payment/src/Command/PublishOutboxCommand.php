<?php

declare(strict_types=1);

namespace App\Payment\Command;

use App\Payment\Repository\PaymentOutboxMessageRepository;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceCancelled;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceFailed;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoicePaid;
use CrudPlatform\IntegrationContracts\Event\V1\PaymentInvoiceRefunded;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:payment:outbox:publish', description: 'Publish pending Payment integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(private readonly PaymentOutboxMessageRepository $repository, private readonly EntityManagerInterface $entityManager, private readonly MessageBusInterface $messageBus) { parent::__construct(); }

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
                'aggregateType' => 'payment_invoice',
                'aggregateId' => $message->getAggregateId(),
                'occurredAt' => $message->getOccurredAt()->format(DATE_ATOM),
                'correlationId' => $message->getCorrelationId() ?? $message->getEventId(),
                'causationId' => $message->getCausationId(),
                'payload' => $message->getPayload(),
            ];
            try {
                $this->messageBus->dispatch(match ($message->getTopic()) {
                    'payment.invoice.paid.v1' => new PaymentInvoicePaid($envelope),
                    'payment.invoice.failed.v1' => new PaymentInvoiceFailed($envelope),
                    'payment.invoice.cancelled.v1' => new PaymentInvoiceCancelled($envelope),
                    'payment.invoice.refunded.v1' => new PaymentInvoiceRefunded($envelope),
                    default => throw new \LogicException('Unsupported Payment outbox topic: ' . $message->getTopic()),
                });
                $message->markPublished();
                ++$count;
            } catch (\Throwable $exception) {
                $this->repository->defer($id, $exception->getMessage(), new \DateTimeImmutable('+5 minutes'));
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Payment outbox message(s).', $count));
        return Command::SUCCESS;
    }
}
