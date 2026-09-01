<?php

declare(strict_types=1);

namespace App\Tests\Payment\Service;

use App\Payment\Entity\PayerDirectory;
use App\Payment\Repository\PayerDirectoryRepository;
use App\Payment\Service\PayerDirectoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PayerDirectoryServiceTest extends TestCase
{
    public function testItUpdatesAnExistingDirectoryFoundByUserUuid(): void
    {
        $directory = new PayerDirectory(null, 'user-uuid');
        $repository = $this->createMock(PayerDirectoryRepository::class);
        $repository->method('findByIdentityUserId')->with(42)->willReturn(null);
        $repository->method('findByUserUuid')->with('user-uuid')->willReturn($directory);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('persist');

        $service = new PayerDirectoryService($entityManager, $repository);

        self::assertSame($directory, $service->upsert(42, 'user-uuid'));
        self::assertSame(42, $directory->getIdentityUserId());
    }

    public function testItPersistsANewDirectoryWhenNoMappingExists(): void
    {
        $repository = $this->createMock(PayerDirectoryRepository::class);
        $repository->method('findByIdentityUserId')->willReturn(null);
        $repository->method('findByUserUuid')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(PayerDirectory::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new PayerDirectoryService($entityManager, $repository);
        $directory = $service->upsert(7, 'new-user-uuid');

        self::assertSame(7, $directory->getIdentityUserId());
        self::assertSame('new-user-uuid', $directory->getUserUuid());
    }
}
