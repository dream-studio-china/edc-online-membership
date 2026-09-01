<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\Entity\TradeOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TradeOutboxService
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
    ): TradeOutboxMessage
    {
        $message = new TradeOutboxMessage($topic, $aggregateType, $aggregateId, $payload, $correlationId, $causationId);
        $this->entityManager->persist($message);

        return $message;
    }
}
