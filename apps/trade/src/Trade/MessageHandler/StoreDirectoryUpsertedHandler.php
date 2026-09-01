<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\TradeStoreDirectory;
use App\Trade\Repository\TradeStoreDirectoryRepository;
use CrudPlatform\IntegrationContracts\Event\V1\StoreDirectoryUpserted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StoreDirectoryUpsertedHandler
{
    public function __construct(
        private TradeStoreDirectoryRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(StoreDirectoryUpserted $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $occurredAt = $message->envelope['occurredAt'] ?? null;
        if (!is_array($payload) || !is_string($occurredAt)
            || !is_string($payload['storeUuid'] ?? null)
            || !is_string($payload['code'] ?? null)
            || !is_string($payload['name'] ?? null)
            || !is_string($payload['status'] ?? null)) {
            throw new \InvalidArgumentException('Invalid store.directory.upserted.v1 envelope.');
        }

        $sourceUpdatedAt = new \DateTimeImmutable($occurredAt);
        $directory = $this->repository->findOneByStoreUuid($payload['storeUuid']);
        if ($directory === null) {
            $directory = new TradeStoreDirectory($payload['storeUuid'], $payload['code'], $payload['name'], $payload['status'], $sourceUpdatedAt);
            $this->entityManager->persist($directory);
        } else {
            $directory->upsert($payload['code'], $payload['name'], $payload['status'], $sourceUpdatedAt);
        }

        $this->entityManager->flush();
    }
}
