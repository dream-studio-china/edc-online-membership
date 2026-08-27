<?php

declare(strict_types=1);

namespace App\Trade\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Service\ProductServiceInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/products', name: 'app-products-')]
class ProductController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly ProductServiceInterface $service,
    ) {
    }

    /**
     * @return array<string, string|bool>
     */
    protected function commonFilter(): array
    {
        return ['status' => 'active', 'isDeleted' => false];
    }
}
