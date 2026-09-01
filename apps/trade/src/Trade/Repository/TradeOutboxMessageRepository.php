<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\TradeOutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TradeOutboxMessage> */
class TradeOutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeOutboxMessage::class);
    }

    /** @return list<TradeOutboxMessage> */
    public function findUnpublished(int $limit = 100): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.availableAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('message.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<int> */
    public function findUnpublishedWithoutCorrelationIds(int $limit): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('message')
            ->select('message.id')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.correlationId IS NULL')
            ->orderBy('message.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function backfillCorrelation(int $id): bool
    {
        return $this->createQueryBuilder('message')
            ->update()
            ->set('message.correlationId', 'message.eventId')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.correlationId IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute() === 1;
    }

    public function claim(int $id, \DateTimeImmutable $until): bool
    {
        return $this->createQueryBuilder('message')
            ->update()
            ->set('message.availableAt', ':until')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->andWhere('message.availableAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('until', $until)
            ->getQuery()
            ->execute() === 1;
    }

    public function defer(int $id, string $error, \DateTimeImmutable $availableAt): void
    {
        $this->createQueryBuilder('message')
            ->update()
            ->set('message.attempts', 'message.attempts + 1')
            ->set('message.lastError', ':error')
            ->set('message.availableAt', ':availableAt')
            ->where('message.id = :id')
            ->andWhere('message.publishedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('error', $error)
            ->setParameter('availableAt', $availableAt)
            ->getQuery()
            ->execute();
    }
}
