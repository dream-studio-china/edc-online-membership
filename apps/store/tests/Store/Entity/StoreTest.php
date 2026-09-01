<?php

declare(strict_types=1);

namespace App\Tests\Store\Entity;

use App\Store\Entity\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    public function testLifecycleAndMutableStoreDetails(): void
    {
        $store = new Store('shanghai-xuhui', 'Xuhui Store', 'Asia/Shanghai');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $store->getUuid());
        self::assertSame('shanghai-xuhui', $store->getCode());
        self::assertSame('Xuhui Store', $store->getName());
        self::assertSame('Asia/Shanghai', $store->getTimezone());
        self::assertTrue($store->isActive());
        self::assertNull($store->getUpdatedAt());

        $store->setContact(['phone' => '123'])
            ->setAddress(['city' => 'Shanghai'])
            ->setSettings(['currency' => 'CNY']);
        self::assertSame(['phone' => '123'], $store->getContact());
        self::assertSame(['city' => 'Shanghai'], $store->getAddress());
        self::assertSame(['currency' => 'CNY'], $store->getSettings());

        $store->setName('Xuhui Flagship')->suspend();
        self::assertSame(Store::STATUS_SUSPENDED, $store->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $store->getUpdatedAt());

        $store->close();
        self::assertSame(Store::STATUS_CLOSED, $store->getStatus());
    }
}
