<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\TradeStoreDirectory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TradeStoreDirectory> */
final class TradeStoreDirectoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeStoreDirectory::class);
    }

    public function findActiveByCode(string $code): ?TradeStoreDirectory
    {
        return $this->findOneBy(['code' => $code, 'status' => 'active']);
    }

    public function findOneByStoreUuid(string $storeUuid): ?TradeStoreDirectory
    {
        return $this->findOneBy(['storeUuid' => $storeUuid]);
    }
}
