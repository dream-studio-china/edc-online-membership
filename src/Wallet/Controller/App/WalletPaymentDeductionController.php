<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Repository\WalletPaymentDeductionRepository;
use App\Wallet\Service\Payment\WalletPaymentDeductionService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/wallet-payment-deductions', name: 'app-wallet-payment-deductions-')]
#[IsGranted('ROLE_USER')]
class WalletPaymentDeductionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly WalletPaymentDeductionService $service,
        private readonly WalletPaymentDeductionRepository $deductionRepository,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        $user = $this->getUser();

        return $this->deductionRepository->createQueryBuilder('entity')
            ->join('entity.wallet', 'w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('entity.createdAt', 'DESC');
    }
}
