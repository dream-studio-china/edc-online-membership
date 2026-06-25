<?php

declare(strict_types=1);

namespace App\Trade\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Service\SpecificationServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/specifications', name: 'app-specifications-')]
class SpecificationController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly SpecificationServiceInterface $service,
    ) {
    }

    #[Route('/by-product/{productId<\d+>}', name: 'by-product', methods: ['GET'])]
    public function listByProductAction(int $productId): Response
    {
        return $this->success(
            $this->service->list(['product' => $productId, 'status' => 'active', 'isDeleted' => false], null, false)
        );
    }
}
