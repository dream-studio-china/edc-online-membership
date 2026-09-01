<?php
declare(strict_types=1);
namespace App\Tests\Wallet\Entity;
use App\Wallet\Entity\Wallet;
use PHPUnit\Framework\TestCase;
final class WalletTest extends TestCase {
    public function testOwnerUuidIsWalletOwned(): void {
        $wallet = new Wallet('owner-uuid', 'eur');
        self::assertSame('owner-uuid', $wallet->getOwnerUuid());
        self::assertSame('EUR', $wallet->getCurrency());
        $wallet->setOwnerUuid('next-owner');
        self::assertSame('next-owner', $wallet->getOwnerUuid());
    }
    public function testDefaults(): void {
        $wallet = new Wallet('owner-uuid');
        self::assertSame(0, $wallet->getBalance());
        self::assertTrue($wallet->isActive());
    }
}
