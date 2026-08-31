<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Service\ProductServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/products', name: 'app-products-')]
class ProductController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly ProductServiceInterface $service,
        private readonly ?StoreContextResolverInterface $storeContextResolver = null,
        private readonly ?\App\Store\Repository\StoreRepository $storeRepository = null,
    ) {
    }

    /** @return array<string, mixed>|DqlExpression */
    protected function commonFilter(): array|DqlExpression
    {
        $base = ['status' => 'active', 'isDeleted' => false];

        $storeContext = $this->storeContextResolver?->resolve();
        if ($storeContext === null || $this->storeRepository === null) {
            // No Store context - only global products
            $base['store'] = null;
            return $base;
        }

        // Visible: global (store IS NULL) OR owned by current Store
        try {
            $store = $this->storeRepository->findOneBy(['uuid' => $storeContext->storeUuid]);
            if ($store === null) {
                return $base + ['store' => null];
            }
            return new DqlExpression(
                '(entity.store IS NULL OR entity.store = :store) AND entity.status = :status AND entity.isDeleted = :isDeleted',
                ['store' => $store, 'status' => 'active', 'isDeleted' => false]
            );
        } catch (\Throwable) {
            return $base + ['store' => null];
        }
    }
}
