<?php

declare(strict_types=1);

namespace App\Store\Command;

use App\Store\Repository\StoreRepository;
use App\Store\Service\OutboxService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:store:outbox:backfill-directory', description: 'Queue Store directory events for Trade projection initialization.')]
final class BackfillStoreDirectoryCommand extends Command
{
    public function __construct(
        private readonly StoreRepository $stores,
        private readonly OutboxService $outbox,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Persist Store directory Outbox events.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stores = $this->stores->findAll();
        if (!$input->getOption('apply')) {
            $output->writeln(sprintf('Dry run: would queue %d Store directory event(s). Re-run with --apply to persist.', count($stores)));

            return Command::SUCCESS;
        }

        foreach ($stores as $store) {
            $this->outbox->record(
                'store.directory.upserted.v1',
                'store',
                $store->getUuid(),
                [
                    'storeUuid' => $store->getUuid(),
                    'code' => $store->getCode(),
                    'name' => $store->getName(),
                    'status' => $store->getStatus(),
                ],
            );
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Queued %d Store directory event(s).', count($stores)));

        return Command::SUCCESS;
    }
}
