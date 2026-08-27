<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Trade\Service\ProductServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/products', name: 'manage-products-')]
#[IsGranted('ROLE_ADMIN')]
class ProductController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'description', 'status', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'description', 'status', 'metadata'];

    public function __construct(
        protected readonly ProductServiceInterface $service,
    ) {
    }
}
