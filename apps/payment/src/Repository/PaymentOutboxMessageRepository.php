<?php

declare(strict_types=1);

namespace App\Payment\Repository;

use App\Payment\Entity\PaymentOutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaymentOutboxMessage> */
final class PaymentOutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PaymentOutboxMessage::class); }
    /** @return list<PaymentOutboxMessage> */
    public function findUnpublished(int $limit = 100): array
    {
        return $this->createQueryBuilder('message')->andWhere('message.publishedAt IS NULL')->andWhere('message.availableAt <= :now')->setParameter('now', new \DateTimeImmutable())->orderBy('message.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
    public function claim(int $id, \DateTimeImmutable $until): bool
    {
        return $this->createQueryBuilder('message')->update()->set('message.availableAt', ':until')->where('message.id = :id')->andWhere('message.publishedAt IS NULL')->andWhere('message.availableAt <= :now')->setParameter('id', $id)->setParameter('now', new \DateTimeImmutable())->setParameter('until', $until)->getQuery()->execute() === 1;
    }
    public function defer(int $id, string $error, \DateTimeImmutable $availableAt): void
    {
        $this->createQueryBuilder('message')->update()->set('message.attempts', 'message.attempts + 1')->set('message.lastError', ':error')->set('message.availableAt', ':availableAt')->where('message.id = :id')->andWhere('message.publishedAt IS NULL')->setParameter('id', $id)->setParameter('error', $error)->setParameter('availableAt', $availableAt)->getQuery()->execute();
    }
}
