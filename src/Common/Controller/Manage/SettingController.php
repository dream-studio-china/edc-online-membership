<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\SettingService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/manage/settings', name: 'manage-settings-')]
#[IsGranted('ROLE_ADMIN')]
class SettingController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['key'];
    protected array $acceptedCreateProperties = ['key', 'value', 'type', 'groupName', 'label', 'description', 'sortOrder'];
    protected array $acceptedUpdateProperties = ['key', 'value', 'type', 'groupName', 'label', 'description', 'sortOrder'];

    public function __construct(
        protected readonly SettingService $service
    ) {}
}
