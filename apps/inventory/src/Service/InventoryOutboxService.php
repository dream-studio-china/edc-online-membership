<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\InventoryOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final class InventoryOutboxService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): InventoryOutboxMessage
    {
        $message = new InventoryOutboxMessage($topic, $aggregateType, $aggregateId, $payload, null, $correlationId, $causationId);
        $this->entityManager->persist($message);

        return $message;
    }
}
