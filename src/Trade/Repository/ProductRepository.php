<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Trade\Entity\Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findById(int $id): ?Product
    {
        return $this->find($id);
    }

    /**
     * @return list<Product>
     */
    public function findNotDeleted(): array
    {
        return $this->findBy(['isDeleted' => false]);
    }

    /**
     * @return list<Product>
     */
    public function findActive(): array
    {
        return $this->findBy(['status' => Product::STATUS_ACTIVE, 'isDeleted' => false]);
    }
}
