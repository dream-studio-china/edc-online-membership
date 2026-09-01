<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\WalletTransaction>
 */
class WalletTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletTransaction::class);
    }

    public function findById(int $id): ?WalletTransaction
    {
        return $this->find($id);
    }

    public function findByUuid(string $uuid): ?WalletTransaction
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByReferenceId(string $referenceId): ?WalletTransaction
    {
        return $this->findOneBy(['referenceId' => $referenceId]);
    }

    /**
     * @return WalletTransaction[]
     */
    public function findByWallet(int $walletId, int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.fromWallet = :wid OR t.toWallet = :wid')
            ->setParameter('wid', $walletId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WalletTransaction[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', WalletTransaction::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalDeposited(): int
    {
        // Count all one-sided credits: deposits + adjustment deposits
        $result = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.type IN (:types)')
            ->andWhere('t.status = :status')
            ->setParameter('types', [WalletTransaction::TYPE_DEPOSIT, WalletTransaction::TYPE_ADJUSTMENT])
            ->setParameter('status', WalletTransaction::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function getTotalDepositedForOwnerUuid(string $ownerUuid): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->innerJoin('t.toWallet', 'w')
            ->where('t.type IN (:types)')
            ->andWhere('t.status = :status')
            ->andWhere('w.ownerUuid = :ownerUuid')
            ->setParameter('types', [WalletTransaction::TYPE_DEPOSIT, WalletTransaction::TYPE_ADJUSTMENT])
            ->setParameter('status', WalletTransaction::STATUS_COMPLETED)
            ->setParameter('ownerUuid', $ownerUuid)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getExpectedBalance(int $walletId): int
    {
        $credits = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.toWallet = :walletId')
            ->andWhere('t.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', WalletTransaction::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $debits = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.fromWallet = :walletId')
            ->andWhere('t.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', WalletTransaction::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $credits - (int) $debits;
    }
}
