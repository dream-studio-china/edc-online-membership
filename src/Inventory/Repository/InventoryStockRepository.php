<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryStock;
use App\Inventory\Entity\Material;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryStock> */
class InventoryStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryStock::class);
    }

    public function findOneByStoreAndMaterial(string $storeUuid, Material $material): ?InventoryStock
    {
        return $this->findOneBy([
            'storeUuid' => $storeUuid,
            'material' => $material,
        ]);
    }
    public function findOneByStoreAndMaterialForUpdate(string $storeUuid, Material $material): ?InventoryStock
    {
        return $this->createQueryBuilder('stock')
            ->andWhere('stock.storeUuid = :storeUuid')
            ->andWhere('stock.material = :material')
            ->setParameter('storeUuid', $storeUuid)
            ->setParameter('material', $material)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
