<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Wallet\Service\TransactionService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/transactions', name: 'manage-transactions-')]
#[IsGranted('ROLE_ADMIN')]
class TransactionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly TransactionService $service
    ) {}
}
