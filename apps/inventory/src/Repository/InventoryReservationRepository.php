<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryReservation> */
class InventoryReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryReservation::class);
    }

    public function findOneByReservationId(string $reservationId): ?InventoryReservation
    {
        return $this->findOneBy(['reservationId' => $reservationId]);
    }

    public function findOneByStoreOrderUuid(string $storeOrderUuid): ?InventoryReservation
    {
        return $this->findOneBy(['storeOrderUuid' => $storeOrderUuid]);
    }

    /** @return list<InventoryReservation> */
    public function findExpiredConfirmed(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.status = :status')
            ->andWhere('reservation.expiresAt IS NOT NULL')
            ->andWhere('reservation.expiresAt <= :now')
            ->setParameter('status', InventoryReservation::STATUS_CONFIRMED)
            ->setParameter('now', $now)
            ->orderBy('reservation.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
