<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Wallet\Service\Payment\WalletPaymentDeductionService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wallet-payment-deductions', name: 'manage-wallet-payment-deductions-')]
#[IsGranted('ROLE_ADMIN')]
class WalletPaymentDeductionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly WalletPaymentDeductionService $service,
    ) {}
}
