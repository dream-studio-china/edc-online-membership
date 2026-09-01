<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Repository\InventoryOutboxMessageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:inventory:outbox:backfill-correlation', description: 'Backfill correlation IDs for unpublished Inventory Outbox messages.')]
final class BackfillOutboxCorrelationCommand extends Command
{
    public function __construct(private readonly InventoryOutboxMessageRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the backfill instead of reporting the selected rows.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to process.', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->limit($input->getOption('limit'));
        if ($limit === null) {
            $output->writeln('The --limit option must be an integer between 1 and 10000.');

            return Command::INVALID;
        }

        $ids = $this->repository->findUnpublishedWithoutCorrelationIds($limit);
        if (!$input->getOption('apply')) {
            $output->writeln(sprintf('Would backfill %d unpublished Inventory Outbox message(s). Pass --apply to write changes.', count($ids)));

            return Command::SUCCESS;
        }

        $updated = 0;
        foreach ($ids as $id) {
            $updated += (int) $this->repository->backfillCorrelation($id);
        }
        $output->writeln(sprintf('Backfilled %d unpublished Inventory Outbox message(s).', $updated));

        return Command::SUCCESS;
    }

    private function limit(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit >= 1 && $limit <= 10000 ? $limit : null;
    }
}
