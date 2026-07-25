<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\StoreOutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoreOutboxMessage> */
class StoreOutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreOutboxMessage::class);
    }

    /** @return list<StoreOutboxMessage> */
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
