<?php

declare(strict_types=1);

namespace App\Payment\Repository;

use App\Payment\Entity\PayerDirectory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PayerDirectory> */
class PayerDirectoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayerDirectory::class);
    }

    public function findByIdentityUserId(int $identityUserId): ?PayerDirectory
    {
        return $this->findOneBy(['identityUserId' => $identityUserId]);
    }

    public function findByUserUuid(string $userUuid): ?PayerDirectory
    {
        return $this->findOneBy(['userUuid' => $userUuid]);
    }
}
