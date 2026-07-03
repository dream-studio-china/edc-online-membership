<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Controller\App\MediaController as AppMediaController;
use App\Common\Service\MediaServiceInterface;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/media', name: 'manage-media-')]
#[IsGranted('ROLE_ADMIN')]
class MediaController extends AppMediaController
{
    use DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path'];
    protected array $acceptedCreateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path', 'storage', 'user', 'alt', 'title', 'width', 'height'];
    protected array $acceptedUpdateProperties = ['filename', 'originalFilename', 'mimeType', 'size', 'path', 'storage', 'user', 'alt', 'title', 'width', 'height'];

    public function __construct(
        MediaServiceInterface $service
    ) {
        parent::__construct($service);
    }

    protected function commonFilter(): array
    {
        return [];
    }
}
