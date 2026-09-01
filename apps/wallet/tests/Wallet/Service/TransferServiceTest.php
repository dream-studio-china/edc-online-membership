<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Service;

use App\Identity\Main\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\WalletTransactionRepository;
use App\Wallet\Service\TransferResult;
use App\Wallet\Service\TransferService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class TransferServiceTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $em;
    private Connection $connection;
    private WalletRepository $walletRepo;
    private WalletTransactionRepository $txRepo;
    private TransferService $service;

    private bool $transactionActive = false;
    private bool $emOpen = true;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('isTransactionActive')->willReturnCallback(fn() => $this->transactionActive);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->em->method('beginTransaction')->willReturnCallback(function (): void {
            $this->transactionActive = true;
        });
        $this->em->method('commit')->willReturnCallback(function (): void {
            $this->transactionActive = false;
        });
        $this->em->method('rollback')->willReturnCallback(function (): void {
            $this->transactionActive = false;
        });
        $this->em->method('isOpen')->willReturnCallback(fn() => $this->emOpen);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManager')->willReturn($this->em);
        $this->walletRepo = $this->createMock(WalletRepository::class);
        $this->txRepo = $this->createMock(WalletTransactionRepository::class);

        $this->service = new TransferService(
            $this->registry,
            $this->walletRepo,
            $this->txRepo,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function createWallet(int $id, int $balance, string $currency = 'CNY', string $status = 'active'): Wallet
    {
        $user = new User();
        $user->setEmail('t@t.com')->setUsername('t');
        $wallet = new Wallet($user->getUuid(), $currency);
        $rId = new \ReflectionProperty(Wallet::class, 'id');
        $rId->setValue($wallet, $id);
        $rBal = new \ReflectionProperty(Wallet::class, 'balance');
        $rBal->setValue($wallet, $balance);
        if ($status === 'frozen') {
            $wallet->setStatus('frozen');
        }
        return $wallet;
    }

    /** @param Wallet $wallet */
    private function setWalletBalance(Wallet $wallet, int $balance): void
    {
        $rBal = new \ReflectionProperty(Wallet::class, 'balance');
        $rBal->setValue($wallet, $balance);
    }

    private function mockQuery(): Query
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(1);
        return $query;
    }

    // ──────────────── deposit ────────────────

    public function testDepositHappyPath(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->with($wallet)->willReturnCallback(function () use ($wallet) {
            $this->setWalletBalance($wallet, 50000);
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->deposit(1, 50000, null, 'Initial funding');

        self::assertSame(50000, $result->toWalletBalanceAfter);
        self::assertSame('completed', $result->transaction->getStatus());
    }

    public function testDepositAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit amount must be positive');
        $this->service->deposit(1, 0);
    }

    public function testDepositWalletNotFound(): void
    {
        $this->walletRepo->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target wallet #999 not found');
        $this->service->deposit(999, 10000);
    }

    public function testDepositWalletFrozen(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY', 'frozen');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(WalletFrozenException::class);
        $this->service->deposit(1, 10000);
    }

    public function testDepositIdempotent(): void
    {
        $wallet = $this->createWallet(1, 50000);
        $existingTx = new WalletTransaction('uuid-1', 50000, WalletTransaction::TYPE_DEPOSIT);
        $existingTx->setToWallet($wallet);
        $existingTx->markCompleted();

        $this->txRepo->method('findByReferenceId')->with('ref-1')->willReturn($existingTx);

        $result = $this->service->deposit(1, 99999, 'ref-1');

        self::assertSame($existingTx, $result->transaction);
        self::assertSame(50000, $result->toWalletBalanceAfter);
    }

    public function testDepositRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 0);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        // Throw AFTER beginTransaction to exercise rollback path
        $this->em->method('createQuery')
            ->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB failure');
        $this->service->deposit(1, 10000);
    }

    public function testDepositEmClosedRecovery(): void
    {
        $wallet = $this->createWallet(1, 0);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->em->method('createQuery')->willThrowException(new \RuntimeException('closed'));

        // After rollback, the EM is "closed", so resetManager and get a new one
        $newEm = $this->createMock(EntityManagerInterface::class);
        $newConn = $this->createMock(Connection::class);
        $newConn->method('isTransactionActive')->willReturn(false);
        $newEm->method('getConnection')->willReturn($newConn);
        $newEm->method('isOpen')->willReturn(true);

        $this->emOpen = false;

        $this->registry->expects(self::once())->method('resetManager');
        $this->registry->expects(self::once())->method('getManager')->willReturn($newEm);

        $this->expectException(\RuntimeException::class);
        $this->service->deposit(1, 10000);
    }

    // ──────────────── transfer ────────────────

    public function testTransferHappyPath(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->willReturnCallback(function (Wallet $w) use ($from, $to) {
            if ($w === $from) { $this->setWalletBalance($from, 70000); }
            if ($w === $to)   { $this->setWalletBalance($to, 30000); }
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->transfer(1, 2, 30000);

        self::assertSame(70000, $result->fromWalletBalanceAfter);
        self::assertSame(30000, $result->toWalletBalanceAfter);
    }

    public function testTransferAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->transfer(1, 2, 0);
    }

    public function testTransferSameWallet(): void
    {
        $this->expectException(SameWalletTransferException::class);
        $this->service->transfer(1, 1, 100);
    }

    public function testTransferSourceNotFound(): void
    {
        $this->walletRepo->method('findByIdForUpdate')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source wallet #999 not found');
        $this->service->transfer(999, 1, 100);
    }

    public function testTransferTargetNotFound(): void
    {
        $from = $this->createWallet(1, 50000);
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, null],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target wallet #2 not found');
        $this->service->transfer(1, 2, 100);
    }

    public function testTransferSourceFrozen(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY', 'frozen');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(WalletFrozenException::class);
        $this->service->transfer(1, 2, 100);
    }

    public function testTransferTargetFrozen(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY', 'frozen');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(WalletFrozenException::class);
        $this->service->transfer(1, 2, 100);
    }

    public function testTransferCurrencyMismatch(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY');
        $to = $this->createWallet(2, 0, 'USD');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Currency mismatch');
        $this->service->transfer(1, 2, 100);
    }

    public function testTransferInsufficientFunds(): void
    {
        $from = $this->createWallet(1, 100, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(InsufficientFundsException::class);
        $this->service->transfer(1, 2, 50000);
    }

    public function testTransferIdempotent(): void
    {
        $from = $this->createWallet(1, 90000);
        $to = $this->createWallet(2, 10000);
        $existingTx = new WalletTransaction('uuid-t', 10000, WalletTransaction::TYPE_TRANSFER);
        $existingTx->setFromWallet($from);
        $existingTx->setToWallet($to);
        $existingTx->markCompleted();

        $this->txRepo->method('findByReferenceId')->with('ref-t')->willReturn($existingTx);

        $result = $this->service->transfer(1, 2, 99999, 'ref-t');

        self::assertSame($existingTx, $result->transaction);
        self::assertSame(90000, $result->fromWalletBalanceAfter);
        self::assertSame(10000, $result->toWalletBalanceAfter);
    }

    public function testTransferDeadlockSafeOrder(): void
    {
        // fromId > toId: swap lock order
        $from = $this->createWallet(2, 100000, 'CNY');
        $to = $this->createWallet(1, 0, 'CNY');
        $this->walletRepo->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnCallback(function (int $id) use ($from, $to): ?Wallet {
                static $calls = [];
                $calls[] = $id;
                // Verify lock order: smaller ID first
                if (count($calls) === 1) {
                    self::assertSame(1, $id);
                }
                return match ($id) {
                    1 => $to,
                    2 => $from,
                    default => null,
                };
            });
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->willReturnCallback(function (Wallet $w) use ($from, $to) {
            if ($w === $from) { $this->setWalletBalance($from, 90000); }
            if ($w === $to)   { $this->setWalletBalance($to, 10000); }
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->transfer(2, 1, 10000);

        self::assertSame(90000, $result->fromWalletBalanceAfter);
    }

    public function testTransferRollbackOnError(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB crash'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->transfer(1, 2, 10000);
    }

    public function testTransferEmClosedRecovery(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('closed'));

        $newEm = $this->createMock(EntityManagerInterface::class);
        $newConn = $this->createMock(Connection::class);
        $newConn->method('isTransactionActive')->willReturn(false);
        $newEm->method('getConnection')->willReturn($newConn);
        $newEm->method('isOpen')->willReturn(true);

        $this->emOpen = false;

        $this->registry->expects(self::once())->method('resetManager');
        $this->registry->expects(self::once())->method('getManager')->willReturn($newEm);

        $this->expectException(\RuntimeException::class);
        $this->service->transfer(1, 2, 10000);
    }
}
