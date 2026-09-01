<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\Security\UserUuidPrincipalInterface;
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

        if (!$user instanceof UserUuidPrincipalInterface) {
            return $this->transactionRepository->createQueryBuilder('entity')->where('1 = 0');
        }

        return $this->transactionRepository->createQueryBuilder('entity')
            ->leftJoin('entity.fromWallet', 'fromWallet')
            ->leftJoin('entity.toWallet', 'toWallet')
            ->andWhere('fromWallet.ownerUuid = :ownerUuid OR toWallet.ownerUuid = :ownerUuid')
            ->setParameter('ownerUuid', $user->getUuid())
            ->addOrderBy('entity.createdAt', 'DESC');
    }
}
