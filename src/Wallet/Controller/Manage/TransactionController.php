<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Service\TransactionService;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Transaction resource: create a WalletTransaction through a transfer using the
 * standard create lifecycle, plus list/detail of the ledger. Update/Delete
 * mixins are intentionally omitted: the ledger is append-only.
 */
#[Route('/manage/transactions', name: 'manage-transactions-')]
#[IsGranted('ROLE_ADMIN')]
class TransactionController extends RestController
{
    use ApiView, CreateApiViewMixin, DetailApiViewMixin, ListApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['fromWalletId', 'toWalletId', 'amount'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['fromWalletId', 'toWalletId', 'amount', 'referenceId', 'description'];

    private ?TransferResult $lastTransfer = null;

    public function __construct(
        protected readonly TransactionService $service,
        private readonly TransferServiceInterface $transferService,
    ) {}

    /**
     * @param array<string, mixed> $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        try {
            $this->lastTransfer = $this->transferService->transfer(
                (int) $content['fromWalletId'],
                (int) $content['toWalletId'],
                (int) $content['amount'],
                isset($content['referenceId']) ? (string) $content['referenceId'] : null,
                isset($content['description']) ? (string) $content['description'] : null,
            );
        } catch (InsufficientFundsException|WalletFrozenException|SameWalletTransferException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NotFoundHttpException($e->getMessage());
            }
            throw $e;
        }

        return $this->lastTransfer->transaction;
    }

    /**
     * @return array<string, mixed>|object|false
     */
    protected function afterCreated(object|false $entity): mixed
    {
        if (!$entity instanceof WalletTransaction || !$this->lastTransfer instanceof TransferResult) {
            return $entity;
        }

        return [
            'transactionId' => $entity->getId(),
            'uuid' => $entity->getUuid(),
            'fromWalletId' => $entity->getFromWallet()?->getId(),
            'toWalletId' => $entity->getToWallet()?->getId(),
            'amount' => $entity->getAmount(),
            'amountFloat' => $entity->getAmountAsFloat(),
            'status' => $entity->getStatus(),
            'fromWalletBalanceAfter' => $this->lastTransfer->fromWalletBalanceAfter,
            'toWalletBalanceAfter' => $this->lastTransfer->toWalletBalanceAfter,
            'createdAt' => $entity->getCreatedAt(),
        ];
    }
}
