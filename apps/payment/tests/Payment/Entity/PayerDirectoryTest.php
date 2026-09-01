<?php

declare(strict_types=1);

namespace App\Tests\Payment\Entity;

use App\Payment\Entity\PayerDirectory;
use PHPUnit\Framework\TestCase;

final class PayerDirectoryTest extends TestCase
{
    public function testItTracksTheIdentityUserMapping(): void
    {
        $payer = new PayerDirectory(null, 'user-uuid');

        self::assertNull($payer->getId());
        self::assertNull($payer->getIdentityUserId());
        self::assertSame('user-uuid', $payer->getUserUuid());
        self::assertSame($payer, $payer->setIdentityUserId(42));
        self::assertSame(42, $payer->getIdentityUserId());
    }
}
