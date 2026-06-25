<?php

declare(strict_types=1);

namespace App\Tests\Payment\Entity;

use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class DeductionTest extends TestCase
{
    public function testDefaultsAndStateMarkers(): void
    {
        $invoice = (new Invoice())->setAmount(1000)->setCurrency('cny');
        $deduction = new Deduction($invoice, 300, 'cny', 'ref-1');

        self::assertNull($deduction->getId());
        self::assertNotSame('', $deduction->getUuid());
        self::assertSame($invoice, $deduction->getInvoice());
        self::assertSame(Deduction::TYPE_WALLET_BALANCE, $deduction->getType());
        self::assertSame(300, $deduction->getAmount());
        self::assertSame('CNY', $deduction->getCurrency());
        self::assertSame(Deduction::STATUS_PENDING, $deduction->getStatus());
        self::assertSame('ref-1', $deduction->getReferenceId());
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        $deduction->markApplied('tx-1', ['fromWalletId' => 1, 'toWalletId' => 2]);
        self::assertSame(Deduction::STATUS_APPLIED, $deduction->getStatus());
        self::assertSame('tx-1', $deduction->getWalletTransactionId());
        self::assertSame(2, $deduction->getMetadata()['toWalletId']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getAppliedAt());

        $deduction->markReleased('tx-2', 'cancelled');
        self::assertSame(Deduction::STATUS_RELEASED, $deduction->getStatus());
        self::assertSame('tx-2', $deduction->getRefundTransactionId());
        self::assertSame('cancelled', $deduction->getMetadata()['releaseReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getReleasedAt());

        $deduction->markRefunded('tx-3', 'refund');
        self::assertSame(Deduction::STATUS_REFUNDED, $deduction->getStatus());
        self::assertSame('tx-3', $deduction->getRefundTransactionId());
        self::assertSame('refund', $deduction->getMetadata()['refundReason']);
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getRefundedAt());

        $deduction->markFailed('failed');
        self::assertSame(Deduction::STATUS_FAILED, $deduction->getStatus());
        self::assertSame('failed', $deduction->getMetadata()['failedReason']);

        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());

        \Closure::bind(function (): void {
            unset($this->createdAt);
        }, $deduction, Deduction::class)();
        $deduction->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $deduction->getCreatedAt());
    }
}
