<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\SingleCreateAndUpdateApiViewMixin;
use App\Core\View\SingleDetailApiViewMixin;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreManagerAuthorizationApiMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}', name: 'store-', requirements: ['scopeId' => '\d+|[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class StoreController extends RestController
{
    use StoreManagerAuthorizationApiMixin, SingleDetailApiViewMixin, SingleCreateAndUpdateApiViewMixin;

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'timezone', 'currency', 'contact', 'address', 'settings'];

    public function __construct(
        protected readonly StoreServiceInterface $service,
        private readonly MembershipServiceInterface $membershipService,
    ) {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        return ['uuid' => $this->storeForManagement()->getUuid()];
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->service;
    }

    protected function membershipService(): MembershipServiceInterface
    {
        return $this->membershipService;
    }
}
