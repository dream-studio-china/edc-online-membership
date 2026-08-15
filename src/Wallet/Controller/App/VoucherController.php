<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Deposit\DepositService;
use App\Wallet\Service\VoucherService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Voucher resource for the current user: list/detail their own vouchers,
 * deposit (self-service top-up into their own wallet) and reverse their own
 * voucher. Update/Delete mixins are intentionally omitted: the ledger is
 * append-only.
 */
#[Route('/app/vouchers', name: 'app-vouchers-')]
#[IsGranted('ROLE_USER')]
class VoucherController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly VoucherService $service,
        private readonly VoucherRepository $voucherRepository,
        private readonly DepositService $depositService,
        private readonly WalletRepository $walletRepository,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        $user = $this->getUser();

        return $this->voucherRepository->createQueryBuilder('entity')
            ->join('entity.wallet', 'w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('entity.createdAt', 'DESC');
    }

    #[Route('/deposit', name: 'deposit', methods: ['POST'])]
    public function depositAction(Request $request): Response
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

        $user = $this->getUser();
        \assert($user instanceof User);
        $wallet = $this->walletRepository->find($walletId);
        if (!$wallet instanceof Wallet || $wallet->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Wallet not found', 404, '', 404);
        }

        try {
            $voucher = $this->depositService->deposit(
                Voucher::VOUCHER_TYPE_MANUAL,
                $voucherId,
                $walletId,
                $amount,
                $currency,
                $referenceId,
                $this->actorName(),
                $reason,
            );

            return $this->success($voucher, 'Deposit completed', 201);
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

        $user = $this->getUser();
        \assert($user instanceof User);
        $voucher = $this->voucherRepository->findByUuid($uuid);
        if (!$voucher instanceof Voucher || $voucher->getWallet()->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Voucher not found', 404, '', 404);
        }

        try {
            $voucher = $this->depositService->reverse($uuid, $reason);

            return $this->success($voucher, 'Deposit reversed', 200);
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
}
