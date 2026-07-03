<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Service\WalletService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/wallets', name: 'app-wallets-')]
#[IsGranted('ROLE_USER')]
class WalletController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly WalletService $service,
    ) {}

    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? ['user' => $user] : ['id' => -1];
    }
}
