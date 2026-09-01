<?php

namespace App\Tests\Core\Utils;

use App\Core\Utils\UUID;
use PHPUnit\Framework\TestCase;

final class UUIDTest extends TestCase
{
    public function testV4ProducesValidUuid(): void
    {
        $uuid = UUID::v4();

        self::assertTrue(UUID::is_valid($uuid));
    }

    public function testV4cHasNoDashes(): void
    {
        $uuid = UUID::v4c();

        self::assertSame(32, strlen($uuid));
        self::assertStringNotContainsString('-', $uuid);
    }
}
