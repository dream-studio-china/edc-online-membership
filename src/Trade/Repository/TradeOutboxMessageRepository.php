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
}
