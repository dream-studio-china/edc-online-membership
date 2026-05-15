<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\MediaService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/manage/media', name: 'manage-media-')]
#[IsGranted('ROLE_ADMIN')]
class MediaController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path'];
    protected array $acceptedCreateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path', 'alt', 'title', 'width', 'height'];
    protected array $acceptedUpdateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path', 'alt', 'title', 'width', 'height'];

    public function __construct(
        protected readonly MediaService $service
    ) {}
}
