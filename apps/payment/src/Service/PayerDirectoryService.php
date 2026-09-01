<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Entity\PayerDirectory;
use App\Payment\Repository\PayerDirectoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PayerDirectoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PayerDirectoryRepository $repository,
    ) {}

    public function upsert(int $identityUserId, string $userUuid): PayerDirectory
    {
        $directory = $this->repository->findByIdentityUserId($identityUserId)
            ?? $this->repository->findByUserUuid($userUuid);
        if ($directory instanceof PayerDirectory) {
            $directory->setIdentityUserId($identityUserId);
            $this->entityManager->flush();

            return $directory;
        }

        $directory = new PayerDirectory($identityUserId, $userUuid);
        $this->entityManager->persist($directory);
        $this->entityManager->flush();

        return $directory;
    }
}
