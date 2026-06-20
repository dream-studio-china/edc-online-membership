<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Trade\Service\SpecificationServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/products/{productId<\d+>}/specifications', name: 'manage-specifications-')]
#[IsGranted('ROLE_ADMIN')]
class SpecificationController extends RestController
{
    use ApiView, ListApiViewMixin, CreateApiViewMixin,
        UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['name', 'price'];
    protected array $acceptedCreateProperties = ['name', 'price', 'status', 'sort'];
    protected array $acceptedUpdateProperties = ['name', 'price', 'status', 'sort'];

    public function __construct(
        protected readonly SpecificationServiceInterface $service,
    ) {
    }

    protected function commonFilter(): array
    {
        $productId = $this->getRequestStack()->getCurrentRequest()?->attributes->getInt('productId', 0);
        return ['product' => $productId];
    }

    protected function defaultCreateValues(): array
    {
        $productId = $this->getRequestStack()->getCurrentRequest()?->attributes->getInt('productId', 0);
        return ['product' => $productId];
    }
}
