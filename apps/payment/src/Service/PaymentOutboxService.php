<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Entity\PaymentOutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PaymentOutboxService
{
    public function __construct(private EntityManagerInterface $entityManager) {}
    /** @param array<string, mixed> $payload */
    public function record(string $topic, string $aggregateType, string $aggregateId, array $payload): PaymentOutboxMessage
    {
        $message = new PaymentOutboxMessage($topic, $aggregateType, $aggregateId, $payload);
        $this->entityManager->persist($message);
        return $message;
    }
}
