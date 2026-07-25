<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Wallet\Service\TransferService;
use App\Wallet\Service\TransferServiceInterface;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/transfers', name: 'manage-transfers-')]
#[IsGranted('ROLE_ADMIN')]
class TransferController extends RestController
{
    use ApiView;

    public function __construct(
        protected readonly TransferServiceInterface $transferService,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        if (empty($content['fromWalletId']) || empty($content['toWalletId']) || empty($content['amount'])) {
            return $this->warning('fromWalletId, toWalletId, and amount are required', 400, '', 400);
        }

        $amount = (int) ($content['amount']);
        if ($amount <= 0) {
            return $this->warning('Amount must be positive', 400, '', 400);
        }

        $fromWalletId = (int) $content['fromWalletId'];
        $toWalletId = (int) $content['toWalletId'];
        $referenceId = $content['referenceId'] ?? null;
        $description = $content['description'] ?? null;

        try {
            $result = $this->transferService->transfer($fromWalletId, $toWalletId, $amount, $referenceId, $description);
            return $this->success([
                'transactionId' => $result->transaction->getId(),
                'uuid' => $result->transaction->getUuid(),
                'fromWalletId' => $fromWalletId,
                'toWalletId' => $toWalletId,
                'amount' => $amount,
                'amountFloat' => $result->transaction->getAmountAsFloat(),
                'status' => $result->transaction->getStatus(),
                'fromWalletBalanceAfter' => $result->fromWalletBalanceAfter,
                'toWalletBalanceAfter' => $result->toWalletBalanceAfter,
                'createdAt' => $result->transaction->getCreatedAt(),
            ], 'Transfer completed', 201);
        } catch (InsufficientFundsException $e) {
            return $this->warning($e->getMessage(), 402, '', 402);
        } catch (WalletFrozenException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (SameWalletTransferException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\RuntimeException $e) {
            $status = str_ends_with($e->getMessage(), 'not found') ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Transfer failed', $status, '', $status);
        }
    }

    #[Route('/deposit', name: 'deposit', methods: ['POST'])]
    public function depositAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        if (empty($content['toWalletId']) || empty($content['amount'])) {
            return $this->warning('toWalletId and amount are required', 400, '', 400);
        }

        $amount = (int) ($content['amount']);
        if ($amount <= 0) {
            return $this->warning('Amount must be positive', 400, '', 400);
        }

        $toWalletId = (int) $content['toWalletId'];
        $referenceId = $content['referenceId'] ?? null;
        $description = $content['description'] ?? null;

        try {
            $result = $this->transferService->deposit($toWalletId, $amount, $referenceId, $description);
            return $this->success([
                'transactionId' => $result->transaction->getId(),
                'uuid' => $result->transaction->getUuid(),
                'toWalletId' => $toWalletId,
                'amount' => $amount,
                'amountFloat' => $result->transaction->getAmountAsFloat(),
                'type' => $result->transaction->getType(),
                'status' => $result->transaction->getStatus(),
                'toWalletBalanceAfter' => $result->toWalletBalanceAfter,
                'createdAt' => $result->transaction->getCreatedAt(),
            ], 'Deposit completed', 201);
        } catch (WalletFrozenException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\RuntimeException $e) {
            $status = str_ends_with($e->getMessage(), 'not found') ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Deposit failed', $status, '', $status);
        }
    }
}
