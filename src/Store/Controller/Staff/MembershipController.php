<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\ScopedListApiViewMixin;
use App\Store\Entity\Membership;
use App\Store\Repository\MembershipRepository;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreManagerAuthorizationApiMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/members', name: 'store-members-', requirements: ['scopeId' => '\d+|[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class MembershipController extends RestController
{
    use StoreManagerAuthorizationApiMixin, ScopedListApiViewMixin;

    public function __construct(
        protected readonly MembershipServiceInterface $service,
        private readonly StoreServiceInterface $storeService,
        private readonly MembershipRepository $membershipRepository,
    ) {
    }

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        $this->authorizeStoreManagementAction();
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    protected function membershipService(): MembershipServiceInterface
    {
        return $this->service;
    }

    protected function scopedListFilter(string $scopeId): \Doctrine\ORM\QueryBuilder
    {
        return $this->membershipRepository->createQueryBuilder('entity')
            ->andWhere('entity.store = :store')
            ->andWhere('entity.status != :revokedStatus')
            ->setParameter('store', $this->storeForManagement())
            ->setParameter('revokedStatus', Membership::STATUS_REVOKED);
    }
}
