<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\PageService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/manage/pages', name: 'manage-pages-')]
#[IsGranted('ROLE_ADMIN')]
class PageController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['title', 'slug'];
    protected array $acceptedCreateProperties = ['title', 'slug', 'body', 'metaTitle', 'metaDescription', 'status', 'publishedAt'];
    protected array $acceptedUpdateProperties = ['title', 'slug', 'body', 'metaTitle', 'metaDescription', 'status', 'publishedAt'];

    public function __construct(
        protected readonly PageService $service
    ) {}
}
