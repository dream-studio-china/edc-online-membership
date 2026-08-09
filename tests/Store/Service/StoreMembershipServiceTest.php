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

    public function testGrantRejectsBlankUserUuid(): void
    {
        $repository = $this->createMock(StoreMembershipRepository::class);
        $service = new StoreMembershipService($this->createContainer($repository), $repository);
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Store membership user UUID is required.');
        $service->grant($store, '   ', StoreMembership::ROLE_MANAGER);
    }

    public function testGrantRequiresPersistedStore(): void
    {
        $repository = $this->createMock(StoreMembershipRepository::class);
        $service = new StoreMembershipService($this->createContainer($repository), $repository);
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Store must be persisted before granting membership.');
        $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);
    }

    public function testGrantCreatesNewMembershipWhenNoneExists(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $this->assignStoreId($store, 7);
        $repository = $this->createMock(StoreMembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(StoreMembership::class)->willReturn($repository);
        $entityManager->method('getReference')->with(Store::class, 7)->willReturn($store);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(StoreMembership::class));
        $service = new StoreMembershipService($this->createContainer($repository, $entityManager), $repository);

        $membership = $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);

        self::assertSame(StoreMembership::ROLE_MANAGER, $membership->getRole());
        self::assertSame($store, $membership->getStore());
    }

    public function testGrantUpdatesExistingMembership(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $this->assignStoreId($store, 7);
        $existing = new StoreMembership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_CLERK);
        $existing->revoke();
        $repository = $this->createMock(StoreMembershipRepository::class);
        $repository->method('findForStoreAndUser')->with($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57')->willReturn($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(StoreMembership::class)->willReturn($repository);
        $entityManager->method('getReference')->with(Store::class, 7)->willReturn($store);
        $service = new StoreMembershipService($this->createContainer($repository, $entityManager), $repository);

        $granted = $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);

        self::assertSame($existing, $granted);
        self::assertSame(StoreMembership::ROLE_MANAGER, $existing->getRole());
        self::assertTrue($existing->isActive());
    }

    private function assignStoreId(Store $store, int $id): void
    {
        (new \ReflectionProperty(Store::class, 'id'))->setValue($store, $id);
    }

    private function createContainer(StoreMembershipRepository $repository, ?EntityManagerInterface $entityManager = null): ContainerInterface
    {
        $entityManager ??= $this->createMock(EntityManagerInterface::class);
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
