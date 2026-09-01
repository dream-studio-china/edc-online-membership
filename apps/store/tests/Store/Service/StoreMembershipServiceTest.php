<?php

declare(strict_types=1);

namespace App\Tests\Store\Service;

use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Repository\MembershipRepository;
use App\Store\Service\MembershipService;
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
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);

        $service = new MembershipService($this->createContainer($repository), $repository);

        self::assertTrue($service->isAuthorized($store, $membership->getUserUuid(), [Membership::ROLE_MANAGER]));
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid(), [Membership::ROLE_FULFILLMENT]));

        $membership->revoke();
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid()));
    }

    public function testRequireAuthorizationReturnsMembershipOrDeniesAccess(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);
        $service = new MembershipService($this->createContainer($repository), $repository);

        self::assertSame($membership, $service->requireAuthorization($store, $membership->getUserUuid(), [Membership::ROLE_MANAGER]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store membership authorization denied.');
        $service->requireAuthorization($store, $membership->getUserUuid(), [Membership::ROLE_OWNER]);
    }

    public function testRequireAuthorizationDeniesMissingMembership(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn(null);
        $service = new MembershipService($this->createContainer($repository), $repository);

        $this->expectException(\RuntimeException::class);
        $service->requireAuthorization($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57');
    }

    private function createContainer(MembershipRepository $repository): ContainerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Membership::class)->willReturn($repository);
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
