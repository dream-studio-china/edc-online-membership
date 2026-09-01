<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Entity;

use App\Identity\Main\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletPaymentDeduction;
use PHPUnit\Framework\TestCase;

final class WalletPaymentDeductionTest extends TestCase
{
    public function testDefaultsAndStateMarkers(): void
    {
        [$invoiceId, $invoiceNo, $wallet] = self::invoiceAndWallet();
        $deduction = new WalletPaymentDeduction($invoiceId, $invoiceNo, 1, $wallet, 2, 300, 'cny', 'ref-1');

        self::assertNull($deduction->getId());
        self::assertNotSame('', $deduction->getUuid());
        self::assertSame($invoiceId, $deduction->getInvoiceId());
        self::assertSame($invoiceNo, $deduction->getInvoiceNo());
        self::assertSame(1, $deduction->getPayerId());
        self::assertSame($wallet, $deduction->getWallet());
        self::assertSame(2, $deduction->getSystemWalletId());
        self::assertSame(WalletPaymentDeduction::TYPE_WALLET_BALANCE, $deduction->getType());
        self::assertSame(300, $deduction->getAmount());
        self::assertSame('CNY', $deduction->getCurrency());
        self::assertSame(WalletPaymentDeduction::STATUS_PENDING, $deduction->getStatus());
        self::assertSame('ref-1', $deduction->getReferenceId());
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        $deduction->markApplied('tx-1', ['fromWalletId' => 1, 'toWalletId' => 2]);
        self::assertSame(WalletPaymentDeduction::STATUS_APPLIED, $deduction->getStatus());
        self::assertSame('tx-1', $deduction->getWalletTransactionId());
        self::assertSame(2, $deduction->getMetadata()['toWalletId']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getAppliedAt());

        $deduction->markReleased('tx-2', 'cancelled');
        self::assertSame(WalletPaymentDeduction::STATUS_RELEASED, $deduction->getStatus());
        self::assertSame('tx-2', $deduction->getReversalTransactionId());
        self::assertSame('cancelled', $deduction->getMetadata()['releaseReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getReleasedAt());

        $deduction->markRefunded('tx-3', 'refund');
        self::assertSame(WalletPaymentDeduction::STATUS_REFUNDED, $deduction->getStatus());
        self::assertSame('tx-3', $deduction->getReversalTransactionId());
        self::assertSame('refund', $deduction->getMetadata()['refundReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getRefundedAt());

        $deduction->markFailed('failed');
        self::assertSame(WalletPaymentDeduction::STATUS_FAILED, $deduction->getStatus());
        self::assertSame('failed', $deduction->getMetadata()['failedReason']);

        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        \Closure::bind(function (): void {
            unset($this->createdAt);
        }, $deduction, WalletPaymentDeduction::class)();
        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());
    }

    public function testStoresProvidedPayerId(): void
    {
        [, , $wallet] = self::invoiceAndWallet();

        $deduction = new WalletPaymentDeduction('invoice-id', 'invoice-no', 2, $wallet, 2, 300, 'CNY', 'ref-1');

        self::assertSame(2, $deduction->getPayerId());
    }

    /** @return array{string, string, Wallet} */
    private static function invoiceAndWallet(): array
    {
        $user = new User();
        self::setId($user, 1);
        $wallet = new Wallet($user->getUuid(), 'CNY');
        self::setId($wallet, 1);

        return ['invoice-id', 'invoice-no', $wallet];
    }

    private static function setId(object $object, int $id): void
    {
        $property = new \ReflectionProperty($object, 'id');
        $property->setValue($object, $id);
    }
}
