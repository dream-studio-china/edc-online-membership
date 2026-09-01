<?php

declare(strict_types=1);

namespace App\Trade\Tests\Entity;

use App\Trade\Entity\TradeStoreDirectory;
use PHPUnit\Framework\TestCase;

final class TradeStoreDirectoryTest extends TestCase
{
    public function testItOnlyAppliesTheMostRecentStoreProjection(): void
    {
        $directory = new TradeStoreDirectory(
            'store-uuid',
            'OLD',
            'Old store',
            'inactive',
            new \DateTimeImmutable('2026-01-02T00:00:00Z'),
        );

        self::assertSame('store-uuid', $directory->getStoreUuid());
        self::assertSame('OLD', $directory->getCode());
        self::assertSame('Old store', $directory->getName());
        self::assertSame('inactive', $directory->getStatus());
        self::assertFalse($directory->isActive());

        $directory->upsert('STALE', 'Stale store', 'active', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        self::assertSame('OLD', $directory->getCode());

        $directory->upsert('NEW', 'New store', 'active', new \DateTimeImmutable('2026-01-03T00:00:00Z'));
        self::assertSame('NEW', $directory->getCode());
        self::assertSame('New store', $directory->getName());
        self::assertSame('active', $directory->getStatus());
        self::assertTrue($directory->isActive());
    }
}
