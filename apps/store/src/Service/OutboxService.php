<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\Entity\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OutboxService
{
    public function __construct(private EntityManagerInterface $entityManager)
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
    ): OutboxMessage
    {
        $message = new OutboxMessage($topic, $aggregateType, $aggregateId, $payload, null, $correlationId, $causationId);
        $this->entityManager->persist($message);

        return $message;
    }
}
