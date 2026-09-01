<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Service;

use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\TransferResult;
use App\Wallet\Service\TransferServiceInterface;
use App\Wallet\Service\WalletPaymentService;
use PHPUnit\Framework\TestCase;

final class WalletPaymentServiceTest extends TestCase
{
    public function testItRoutesPaymentsAndRefundsBetweenTheOwnerAndSystemWallet(): void
    {
        $wallet = new Wallet('owner-uuid', 'USD');
        (new \ReflectionProperty(Wallet::class, 'id'))->setValue($wallet, 7);

        $repository = $this->createMock(WalletRepository::class);
        $repository->expects(self::exactly(4))
            ->method('findByOwnerUuidAndCurrency')
            ->with('owner-uuid', 'USD')
            ->willReturn($wallet);

        $result = new TransferResult(new WalletTransaction('transaction-uuid', 100, WalletTransaction::TYPE_TRANSFER), 0, 0);
        $calls = [];
        $transfer = $this->createMock(TransferServiceInterface::class);
        $transfer->expects(self::exactly(4))
            ->method('transfer')
            ->willReturnCallback(static function (int $from, int $to) use (&$calls, $result): TransferResult {
                $calls[] = [$from, $to];

                return $result;
            });

        $service = new WalletPaymentService($repository, $transfer);

        self::assertSame($result, $service->pay('owner-uuid', 'USD', 1, 100, 'pay-1', 'Payment'));
        self::assertSame($result, $service->refund('owner-uuid', 'USD', 1, 100, 'refund-1', 'Refund'));
        self::assertSame('transaction-uuid', $service->debitOwner('owner-uuid', 'USD', 1, 100, 'debit-1', 'Debit'));
        self::assertSame('transaction-uuid', $service->creditOwner('owner-uuid', 'USD', 1, 100, 'credit-1', 'Credit'));
        self::assertSame([[7, 1], [1, 7], [7, 1], [1, 7]], $calls);
    }

    public function testItRejectsPaymentWhenTheOwnerWalletIsMissing(): void
    {
        $repository = $this->createMock(WalletRepository::class);
        $repository->method('findByOwnerUuidAndCurrency')->willReturn(null);

        $service = new WalletPaymentService($repository, $this->createMock(TransferServiceInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No USD wallet found for payer.');

        $service->pay('owner-uuid', 'USD', 1, 100, 'pay-1', 'Payment');
    }

    public function testItRejectsOwnerDebitsWhenTheOwnerWalletIsMissing(): void
    {
        $repository = $this->createMock(WalletRepository::class);
        $repository->method('findByOwnerUuidAndCurrency')->willReturn(null);

        $service = new WalletPaymentService($repository, $this->createMock(TransferServiceInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No USD wallet found for owner.');

        $service->debitOwner('owner-uuid', 'USD', 1, 100, 'debit-1', 'Debit');
    }
}
