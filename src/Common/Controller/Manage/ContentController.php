<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\ContentService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/manage/contents', name: 'manage-contents-')]
#[IsGranted('ROLE_ADMIN')]
class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['title'];
    protected array $acceptedCreateProperties = ['title', 'body'];
    protected array $acceptedUpdateProperties = ['title', 'body'];

    public function __construct(
        protected readonly ContentService $service
    ) {}
}
