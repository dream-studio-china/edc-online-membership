<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\Store;
use App\Store\Entity\StoreMembership;
use PHPUnit\Framework\TestCase;

final class StoreMembershipTest extends TestCase
{
    public function testRoleAndStatusLifecycle(): void
    {
        $membership = new StoreMembership(new Store('xuhui', 'Xuhui', 'Asia/Shanghai'), '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', StoreMembership::ROLE_MANAGER);

        self::assertTrue($membership->isActive());
        self::assertSame(StoreMembership::ROLE_MANAGER, $membership->getRole());

        $membership->suspend()->revoke();
        self::assertSame(StoreMembership::STATUS_REVOKED, $membership->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $membership->getUpdatedAt());
    }

    public function testInvalidRoleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StoreMembership(new Store('xuhui', 'Xuhui', 'Asia/Shanghai'), '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'administrator');
    }
}
