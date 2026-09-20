<?php

declare(strict_types=1);

namespace App\Store\View;

use App\Core\View\ApiView;
use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

trait StoreManagerAuthorizationApiMixin
{
    use ApiView;

    protected string $storeScopeRouteParameter = 'scopeId';

    abstract protected function storeService(): StoreServiceInterface;

    abstract protected function membershipService(): MembershipServiceInterface;

    protected function storeForManagement(): Store
    {
        $scopeId = $this->getRequestStack()->getCurrentRequest()?->attributes->get($this->storeScopeRouteParameter);
        if (!is_string($scopeId) || $scopeId === '') {
            throw new NotFoundHttpException('Store not found.');
        }

        $store = $this->storeService()->get($this->identifierCriteria($scopeId), false);
        if (!$store instanceof Store) {
            throw new NotFoundHttpException('Store not found.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Access denied.');
        }
        try {
            $this->membershipService()->requireAuthorization($store, $user->getUuid(), [Membership::ROLE_OWNER, Membership::ROLE_MANAGER]);
        } catch (\RuntimeException) {
            throw new AccessDeniedHttpException('Store owner or manager membership is required.');
        }

        return $store;
    }

    protected function authorizeStoreManagementAction(): void
    {
        try {
            $this->storeForManagement();
        } catch (AccessDeniedHttpException|NotFoundHttpException $exception) {
            throw new AccessDeniedException($exception->getMessage(), previous: $exception);
        }
    }
}
