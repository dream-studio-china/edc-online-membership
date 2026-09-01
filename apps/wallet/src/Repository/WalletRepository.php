<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\Wallet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\Wallet>
 */
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

    /** @return Wallet[] */
    public function findByOwnerUuid(string $ownerUuid): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.ownerUuid = :ownerUuid')
            ->setParameter('ownerUuid', $ownerUuid)
            ->orderBy('w.currency', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByOwnerUuidAndCurrency(string $ownerUuid, string $currency): ?Wallet
    {
        return $this->findOneBy(['ownerUuid' => $ownerUuid, 'currency' => strtoupper($currency)]);
    }

    public function getTotalBalance(): int
    {
        $result = $this->createQueryBuilder('w')
            ->select('COALESCE(SUM(w.balance), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function getTotalBalanceForOwnerUuid(string $ownerUuid): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COALESCE(SUM(w.balance), 0)')
            ->where('w.ownerUuid = :ownerUuid')
            ->setParameter('ownerUuid', $ownerUuid)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
