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
        try {
            $storeContext = $this->storeContextResolver?->resolve();
        } catch (\Throwable) {
            $storeContext = null;
        }

        if ($storeContext === null || $this->storeRepository === null) {
            return $this->globalProductExpression();
        }

        try {
            $store = $this->storeRepository->findOneBy(['uuid' => $storeContext->storeUuid]);
        } catch (\Throwable) {
            return $this->globalProductExpression();
        }

        if ($store === null) {
            return $this->globalProductExpression();
        }

        return new DqlExpression(
            '(!entity.getStore() || entity.getStore() == store) && entity.getStatus() == status && entity.getIsDeleted() == isDeleted',
            ['store' => $store, 'status' => 'active', 'isDeleted' => false]
        );
    }

    private function globalProductExpression(): DqlExpression
    {
        return new DqlExpression(
            'entity.getStatus() == status && entity.getIsDeleted() == isDeleted && !entity.getStore()',
            ['status' => 'active', 'isDeleted' => false]
        );
    }
}
