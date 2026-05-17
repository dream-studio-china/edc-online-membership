<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
