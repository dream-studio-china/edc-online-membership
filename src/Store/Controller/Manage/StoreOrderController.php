<?php

declare(strict_types=1);

namespace App\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Controller\Concerns\StoreOrderDirectVerifyTrait;
use App\Store\Service\StoreOrderDirectVerifyService;
use App\Store\Service\StoreOrderServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/store-orders', name: 'manage-store-orders-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreOrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, StoreOrderDirectVerifyTrait;

    public function __construct(
        protected readonly StoreOrderServiceInterface $service,
        private readonly StoreOrderDirectVerifyService $directVerifyService,
    ) {
    }

    protected function getDirectVerifyService(): StoreOrderDirectVerifyService
    {
        return $this->directVerifyService;
    }

    #[Route('/{uuid}/direct-verify', name: 'direct_verify', methods: ['POST'], requirements: ['uuid' => '\d+|[0-9a-fA-F-]{36}'])]
    public function directVerifyAction(Request $request, string $uuid): Response
    {
        $storeOrder = $this->service->get($this->mixIdToCommonFilter($uuid), false);
        if (!$storeOrder instanceof \App\Store\Entity\StoreOrder) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }

        return $this->handleDirectVerify($request, $storeOrder);
    }
}
