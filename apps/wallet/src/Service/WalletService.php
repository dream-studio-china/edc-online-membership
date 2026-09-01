<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Repository\WalletTransactionRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wallet\Entity\Wallet> */
class WalletService extends BaseService
{
    public function __construct(
        ContainerInterface $container,
        private readonly WalletTransactionRepository $transactionRepo,
    ) {
        parent::__construct($container, Wallet::class);
    }

    /**
     * @return array<string, bool|int>
     */
    public function verifyBalance(): array
    {
        $totalBalance = $this->getWalletRepository()->getTotalBalance();
        $totalDeposited = $this->transactionRepo->getTotalDeposited();
        $walletCount = $this->getWalletRepository()->count([]);

        return [
            'totalBalance' => $totalBalance,
            'totalDeposited' => $totalDeposited,
            'discrepancy' => $totalDeposited - $totalBalance,
            'matches' => $totalBalance === $totalDeposited,
            'walletCount' => $walletCount,
        ];
    }

    /**
     * @return array<string, bool|int>
     */
    public function verifyBalanceForOwnerUuid(string $ownerUuid): array
    {
        $totalBalance = $this->getWalletRepository()->getTotalBalanceForOwnerUuid($ownerUuid);
        $totalDeposited = $this->transactionRepo->getTotalDepositedForOwnerUuid($ownerUuid);
        $walletCount = $this->getWalletRepository()->count(['ownerUuid' => $ownerUuid]);

        return [
            'totalBalance' => $totalBalance,
            'totalDeposited' => $totalDeposited,
            'discrepancy' => $totalDeposited - $totalBalance,
            'matches' => $totalBalance === $totalDeposited,
            'walletCount' => $walletCount,
        ];
    }

    /**
     * Reconcile every wallet: compare actual balance against transaction-derived
     * expected balance. For wallets that have more balance than their transaction
     * history supports (legacy data from old direct-set balance), create an
     * adjustment deposit transaction to acknowledge the balance — without
     * touching the wallet balance itself.
     *
     * actual < expected (real gap): reported, not auto-corrected.
     *
     * Idempotent: re-running produces no new adjustments when books are balanced.
     *
     * @return array{reconciled: int, adjustments: list<array<string,mixed>>}
     */
    public function reconcile(): array
    {
        $wallets = $this->getWalletRepository()->findAll();
        $adjustments = [];
        $reconciled = 0;

        $uuidFn = static function (): string {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        foreach ($wallets as $wallet) {
            if (!($wallet instanceof Wallet) || $wallet->getId() === null) {
                continue;
            }

            $walletId = $wallet->getId();
            $actual = $wallet->getBalance();
            $expected = $this->transactionRepo->getExpectedBalance($walletId);
            $diff = $actual - $expected;

            if ($diff === 0) {
                continue;
            }

            if ($diff < 0) {
                $adjustments[] = [
                    'walletId' => $walletId,
                    'actual' => $actual,
                    'expected' => $expected,
                    'diff' => $diff,
                    'action' => 'skipped_negative',
                    'note' => 'Balance less than expected — manual review required',
                ];
                continue;
            }

            // actual > expected: legacy balance, create deposit to bridge the gap.
            // The wallet balance stays as-is; the deposit transaction acknowledges it.
            $tx = new WalletTransaction($uuidFn(), $diff, WalletTransaction::TYPE_ADJUSTMENT);
            $tx->setToWallet($wallet);
            $tx->setDescription(sprintf(
                'Reconciliation — actual %d, expected %d, gap +%d acknowledged as deposit',
                $actual,
                $expected,
                $diff,
            ));
            $tx->markCompleted();

            $this->em->persist($tx);
            $this->em->flush();

            $adjustments[] = [
                'walletId' => $walletId,
                'actual' => $actual,
                'expected' => $expected,
                'diff' => $diff,
                'action' => 'deposited',
                'newBalance' => $wallet->getBalance(),
            ];
            $reconciled++;
        }

        return [
            'reconciled' => $reconciled,
            'adjustments' => $adjustments,
        ];
    }

    private function getWalletRepository(): \App\Wallet\Repository\WalletRepository
    {
        $repository = $this->getRepository(Wallet::class);
        if (!$repository instanceof \App\Wallet\Repository\WalletRepository) {
            throw new \LogicException('Wallet repository is not available.');
        }

        return $repository;
    }
}
