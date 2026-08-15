<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Identity\Entity\User;
use App\Wallet\Entity\WalletVoucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Service\Deposit\WalletDepositService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/deposits', name: 'manage-deposits-')]
#[IsGranted('ROLE_ADMIN')]
class DepositController extends RestController
{
    use ApiView;

    public function __construct(
        protected readonly WalletDepositService $depositService,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $walletId = (int) ($content['walletId'] ?? 0);
        $amount = (int) ($content['amount'] ?? 0);
        $currency = (string) ($content['currency'] ?? '');
        $referenceId = (string) ($content['referenceId'] ?? '');
        $voucherId = (string) ($content['voucherId'] ?? $referenceId);
        $reason = isset($content['reason']) ? (string) $content['reason'] : null;

        if ($walletId <= 0 || $amount <= 0 || $currency === '' || $referenceId === '') {
            return $this->warning('walletId, amount, currency, and referenceId are required', 400, '', 400);
        }

        try {
            $voucher = $this->depositService->deposit(
                WalletVoucher::VOUCHER_TYPE_MANUAL,
                $voucherId,
                $walletId,
                $amount,
                $currency,
                $referenceId,
                $this->actorName(),
                $reason,
            );

            return $this->success($this->serializeVoucher($voucher), 'Deposit completed', 201);
        } catch (WalletFrozenException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (InsufficientFundsException $e) {
            return $this->warning($e->getMessage(), 402, '', 402);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\RuntimeException $e) {
            $status = $this->isNotFound($e) ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Deposit failed', $status, '', $status);
        }
    }

    #[Route('/{uuid}/reverse', name: 'reverse', methods: ['POST'])]
    public function reverseAction(string $uuid, Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];
        $reason = (string) ($content['reason'] ?? 'Deposit reversed');

        try {
            $voucher = $this->depositService->reverse($uuid, $reason);

            return $this->success($this->serializeVoucher($voucher), 'Deposit reversed', 200);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\LogicException $e) {
            return $this->warning($e->getMessage(), 409, '', 409);
        } catch (\RuntimeException $e) {
            $status = $this->isNotFound($e) ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Reversal failed', $status, '', $status);
        }
    }

    private function isNotFound(\RuntimeException $e): bool
    {
        return str_contains($e->getMessage(), 'not found');
    }

    private function actorName(): string
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getUsername();
        }

        return 'system';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVoucher(WalletVoucher $voucher): array
    {
        return [
            'uuid' => $voucher->getUuid(),
            'direction' => $voucher->getDirection(),
            'fundSource' => $voucher->getFundSource(),
            'voucherType' => $voucher->getVoucherType(),
            'voucherId' => $voucher->getVoucherId(),
            'walletId' => $voucher->getWallet()->getId(),
            'amount' => $voucher->getAmount(),
            'amountFloat' => $voucher->getAmount() / 100,
            'currency' => $voucher->getCurrency(),
            'status' => $voucher->getStatus(),
            'referenceId' => $voucher->getReferenceId(),
            'createdBy' => $voucher->getCreatedBy(),
            'walletTransactionId' => $voucher->getWalletTransactionId(),
            'reversalTransactionId' => $voucher->getReversalTransactionId(),
            'createdAt' => $voucher->getCreatedAt(),
        ];
    }
}
