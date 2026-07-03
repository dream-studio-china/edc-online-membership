<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Repository\WalletTransactionRepository;
use App\Wallet\Service\TransactionService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/transactions', name: 'app-transactions-')]
#[IsGranted('ROLE_USER')]
class TransactionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly TransactionService $service,
        private readonly WalletTransactionRepository $transactionRepository,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        $user = $this->getUser();

        return $this->transactionRepository->createQueryBuilder('entity')
            ->leftJoin('entity.fromWallet', 'fromWallet')
            ->leftJoin('entity.toWallet', 'toWallet')
            ->andWhere('fromWallet.user = :user OR toWallet.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('entity.createdAt', 'DESC');
    }
}
