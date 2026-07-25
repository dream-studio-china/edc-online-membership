<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\Store;
use App\Store\Entity\StoreMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoreMembership> */
class StoreMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreMembership::class);
    }

    public function findForStoreAndUser(Store $store, string $userUuid): ?StoreMembership
    {
        return $this->findOneBy(['store' => $store, 'userUuid' => $userUuid]);
    }
}
