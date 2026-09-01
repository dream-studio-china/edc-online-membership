<?php

namespace App\Tests\Wallet\Entity;

use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Entity\Wallet;
use App\Identity\Main\Entity\User;
use PHPUnit\Framework\TestCase;

final class WalletTransactionTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $tx = new WalletTransaction('uuid-123', 5000, WalletTransaction::TYPE_TRANSFER);

        self::assertSame('uuid-123', $tx->getUuid());
        self::assertSame(5000, $tx->getAmount());
        self::assertSame(50.00, $tx->getAmountAsFloat());
        self::assertSame(WalletTransaction::TYPE_TRANSFER, $tx->getType());
        self::assertSame(WalletTransaction::STATUS_PENDING, $tx->getStatus());
        self::assertNull($tx->getFromWallet());
        self::assertNull($tx->getToWallet());
        self::assertNull($tx->getReferenceId());
        self::assertNull($tx->getDescription());
        self::assertNull($tx->getMetadata());
        self::assertNull($tx->getCompletedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCreatedAt());
    }

    public function testConstructorInvalidTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WalletTransaction('uid', 100, 'invalid_type');
    }

    public function testAllValidTypes(): void
    {
        foreach (['deposit', 'withdrawal', 'transfer', 'fee', 'refund'] as $type) {
            $tx = new WalletTransaction('uid-' . $type, 100, $type);
            self::assertSame($type, $tx->getType());
        }
    }

    public function testSetFromAndToWallet(): void
    {
        $user = new User();
        $from = new Wallet($user->getUuid());
        $to = new Wallet($user->getUuid(), 'EUR');

        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->setFromWallet($from);
        $tx->setToWallet($to);

        self::assertSame($from, $tx->getFromWallet());
        self::assertSame($to, $tx->getToWallet());
    }

    public function testSetReferenceId(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->setReferenceId('ref-001');
        self::assertSame('ref-001', $tx->getReferenceId());

        $tx->setReferenceId(null);
        self::assertNull($tx->getReferenceId());
    }

    public function testSetDescription(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->setDescription('Transfer to friend');
        self::assertSame('Transfer to friend', $tx->getDescription());
    }

    public function testSetMetadata(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->setMetadata('{"ip":"1.2.3.4"}');
        self::assertSame('{"ip":"1.2.3.4"}', $tx->getMetadata());
    }

    public function testSetInvalidStatus(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');

        $this->expectException(\InvalidArgumentException::class);
        $tx->setStatus('unknown_status');
    }

    public function testAllValidStatuses(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');

        foreach (['pending', 'completed', 'failed', 'reversed'] as $status) {
            $tx->setStatus($status);
            self::assertSame($status, $tx->getStatus());
        }
    }

    public function testMarkCompleted(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->markCompleted();

        self::assertSame(WalletTransaction::STATUS_COMPLETED, $tx->getStatus());
        self::assertTrue($tx->isCompleted());
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCompletedAt());
    }

    public function testMarkFailed(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $tx->markFailed();

        self::assertSame(WalletTransaction::STATUS_FAILED, $tx->getStatus());
        self::assertFalse($tx->isCompleted());
    }

    public function testPrePersist(): void
    {
        $reflection = new \ReflectionClass(WalletTransaction::class);
        $tx = $reflection->newInstanceWithoutConstructor();

        $tx->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCreatedAt());
    }

    public function testPrePersistKeepsExisting(): void
    {
        $tx = new WalletTransaction('uid', 100, 'transfer');
        $createdAt = $tx->getCreatedAt();

        $tx->prePersist();
        self::assertSame($createdAt, $tx->getCreatedAt());
    }

    public function testAmountBoundaryOneCent(): void
    {
        $tx = new WalletTransaction('uid', 1, 'transfer');
        self::assertSame(1, $tx->getAmount());
        self::assertSame(0.01, $tx->getAmountAsFloat());
    }

    public function testAmountBoundaryLarge(): void
    {
        $tx = new WalletTransaction('uid', 999999999999, 'transfer');
        self::assertSame(999999999999, $tx->getAmount());
        self::assertSame(9999999999.99, $tx->getAmountAsFloat());
    }

    public function testUuidPersistenceAcrossOperations(): void
    {
        $tx = new WalletTransaction('original-uuid', 100, 'transfer');
        $tx->markCompleted();

        self::assertSame('original-uuid', $tx->getUuid(), 'UUID should not change after markCompleted');
    }

    public function testToString(): void
    {
        $tx = new WalletTransaction('test-uuid', 10000, 'transfer');
        $str = (string) $tx;

        self::assertStringContainsString('transfer', $str);
        self::assertStringContainsString('test-uuid', $str);
        self::assertStringContainsString('100.00', $str);
    }
}
