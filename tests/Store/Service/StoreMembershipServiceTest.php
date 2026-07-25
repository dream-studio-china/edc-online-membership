<?php

declare(strict_types=1);

namespace App\Tests\Store\Service;

use App\Store\Entity\Store;
use App\Store\Entity\StoreMembership;
use App\Store\Repository\StoreMembershipRepository;
use App\Store\Service\StoreMembershipService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreMembershipServiceTest extends TestCase
{
    public function testAuthorizationRequiresAnActiveMembershipWithAnAllowedRole(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $membership = new StoreMembership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);
        $repository = $this->createMock(StoreMembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);

        $service = new StoreMembershipService($this->createContainer($repository), $repository);

        self::assertTrue($service->isAuthorized($store, $membership->getUserUuid(), [StoreMembership::ROLE_MANAGER]));
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid(), [StoreMembership::ROLE_FULFILLMENT]));

        $membership->revoke();
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid()));
    }

    public function testRequireAuthorizationReturnsMembershipOrDeniesAccess(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $membership = new StoreMembership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);
        $repository = $this->createMock(StoreMembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);
        $service = new StoreMembershipService($this->createContainer($repository), $repository);

        self::assertSame($membership, $service->requireAuthorization($store, $membership->getUserUuid(), [StoreMembership::ROLE_MANAGER]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store membership authorization denied.');
        $service->requireAuthorization($store, $membership->getUserUuid(), [StoreMembership::ROLE_OWNER]);
    }

    public function testRequireAuthorizationDeniesMissingMembership(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $repository = $this->createMock(StoreMembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn(null);
        $service = new StoreMembershipService($this->createContainer($repository), $repository);

        $this->expectException(\RuntimeException::class);
        $service->requireAuthorization($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57');
    }

    private function createContainer(StoreMembershipRepository $repository): ContainerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(StoreMembership::class)->willReturn($repository);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'doctrine.orm.entity_manager' => $entityManager,
            'logger' => $this->createMock(LoggerInterface::class),
            'security.token_storage' => $this->createMock(TokenStorageInterface::class),
            default => null,
        });

        return $container;
    }
}
