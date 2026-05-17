<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Wallet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

class WalletRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wallet::class);
    }

    public function findById(int $id): ?Wallet
    {
        return $this->find($id);
    }

    /**
     * Find wallet by user and currency with a pessimistic write lock.
     * Use this before debiting/crediting to prevent race conditions.
     */
    public function findByIdForUpdate(int $id): ?Wallet
    {
        return $this->createQueryBuilder('w')
            ->where('w.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Find all wallets belonging to a user.
     * @return Wallet[]
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('w.currency', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndCurrency(int $userId, string $currency): ?Wallet
    {
        return $this->findOneBy(['user' => $userId, 'currency' => strtoupper($currency)]);
    }
}
