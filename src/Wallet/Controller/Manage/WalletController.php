<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Wallet\Service\WalletService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/manage/wallets', name: 'manage-wallets-')]
#[IsGranted('ROLE_ADMIN')]
class WalletController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['user', 'currency'];
    protected array $acceptedCreateProperties = ['user', 'currency', 'balance', 'status', 'label'];
    protected array $acceptedUpdateProperties = ['status', 'label', 'currency'];

    public function __construct(
        protected readonly WalletService $service
    ) {}
}
