<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Store;
use App\Store\Entity\StoreMembership;
use App\Store\Repository\StoreMembershipRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<StoreMembership> */
class StoreMembershipService extends BaseService implements StoreMembershipServiceInterface
{
    public function __construct(ContainerInterface $container, private readonly StoreMembershipRepository $membershipRepository)
    {
        parent::__construct($container, StoreMembership::class);
    }

    public function grant(Store $store, string $userUuid, string $role): StoreMembership
    {
        if (trim($userUuid) === '') {
            throw new \InvalidArgumentException('Store membership user UUID is required.');
        }

        $storeId = $store->getId();
        if ($storeId === null) {
            throw new \InvalidArgumentException('Store must be persisted before granting membership.');
        }

        return $this->wrapInTransaction(function () use ($storeId, $userUuid, $role): StoreMembership {
            $managedStore = $this->getEntityManager()->getReference(Store::class, $storeId);
            \assert($managedStore instanceof Store);
            $membership = $this->membershipRepository->findForStoreAndUser($managedStore, $userUuid);
            if ($membership === null) {
                $membership = new StoreMembership($managedStore, $userUuid, $role);
                $this->getEntityManager()->persist($membership);
                return $membership;
            }

            $membership->setRole($role)->activate();
            return $membership;
        });
    }

    public function isAuthorized(Store $store, string $userUuid, array $allowedRoles = []): bool
    {
        $membership = $this->membershipRepository->findForStoreAndUser($store, $userUuid);
        return $membership !== null
            && $membership->isActive()
            && ($allowedRoles === [] || in_array($membership->getRole(), $allowedRoles, true));
    }

    public function requireAuthorization(Store $store, string $userUuid, array $allowedRoles = []): StoreMembership
    {
        $membership = $this->membershipRepository->findForStoreAndUser($store, $userUuid);
        if ($membership === null || !$membership->isActive() || ($allowedRoles !== [] && !in_array($membership->getRole(), $allowedRoles, true))) {
            throw new \RuntimeException('Store membership authorization denied.');
        }

        return $membership;
    }
}
