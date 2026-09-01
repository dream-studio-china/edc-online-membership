<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\Specification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Trade\Entity\Specification>
 */
class SpecificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Specification::class);
    }

    public function findById(int $id): ?Specification
    {
        return $this->find($id);
    }

    /**
     * @return list<Specification>
     */
    public function findByProduct(int $productId): array
    {
        return $this->findBy([
            'product' => $productId,
            'isDeleted' => false,
        ], ['sort' => 'ASC']);
    }

    /**
     * @return list<Specification>
     */
    public function findActiveByProduct(int $productId): array
    {
        return $this->findBy([
            'product' => $productId,
            'status' => Specification::STATUS_ACTIVE,
            'isDeleted' => false,
        ], ['sort' => 'ASC']);
    }

    public function findByIdForUpdate(int $id): ?Specification
    {
        return $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
