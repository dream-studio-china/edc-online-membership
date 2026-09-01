<?php

declare(strict_types=1);

namespace App\Store\EventListener;

use App\Store\Entity\Store;
use App\Store\Entity\OutboxMessage;
use App\Store\Service\OutboxService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class StoreDirectoryOutboxListener
{
    public function __construct(private OutboxService $outbox)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        foreach (array_merge($unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()) as $entity) {
            if (!$entity instanceof Store) {
                continue;
            }

            $message = $this->outbox->record(
                'store.directory.upserted.v1',
                'store',
                $entity->getUuid(),
                [
                    'storeUuid' => $entity->getUuid(),
                    'code' => $entity->getCode(),
                    'name' => $entity->getName(),
                    'status' => $entity->getStatus(),
                ],
            );
            $unitOfWork->computeChangeSet($entityManager->getClassMetadata(OutboxMessage::class), $message);
        }
    }
}
