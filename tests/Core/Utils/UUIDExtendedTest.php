<?php

namespace App\Tests\Core\Utils;

use App\Core\Utils\UUID;
use PHPUnit\Framework\TestCase;

final class UUIDExtendedTest extends TestCase
{
    public function testIsValidWithStandardUuid(): void
    {
        self::assertTrue(UUID::is_valid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testIsValidWithBraces(): void
    {
        self::assertTrue(UUID::is_valid('{550e8400-e29b-41d4-a716-446655440000}'));
    }

    public function testIsValidWithoutDashes(): void
    {
        self::assertTrue(UUID::is_valid('550e8400e29b41d4a716446655440000'));
    }

    public function testIsValidInvalidString(): void
    {
        self::assertFalse(UUID::is_valid('not-a-uuid'));
    }

    public function testIsValidEmptyString(): void
    {
        self::assertFalse(UUID::is_valid(''));
    }

    public function testV4FormatIsCorrect(): void
    {
        $uuid = UUID::v4();
        // Version 4: position 14 should be '4'
        self::assertSame('4', $uuid[14]);
        // Variant: position 19 should be in [8,9,a,b]
        self::assertStringContainsString($uuid[19], '89ab');
    }

    public function testV4Uniqueness(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = UUID::v4();
        }
        self::assertCount(100, array_unique($uuids));
    }

    public function testV3ProducesDeterministic(): void
    {
        $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $uuid1 = UUID::v3($ns, 'hello');
        $uuid2 = UUID::v3($ns, 'hello');
        self::assertSame($uuid1, $uuid2);
        self::assertTrue(UUID::is_valid($uuid1));
    }

    public function testV3VersionBits(): void
    {
        $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $uuid = UUID::v3($ns, 'test');
        self::assertNotFalse($uuid);
        self::assertSame('3', $uuid[14]); // version 3
    }

    public function testV3InvalidNamespace(): void
    {
        self::assertFalse(UUID::v3('not-a-namespace', 'name'));
    }

    public function testV5ProducesDeterministic(): void
    {
        $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $uuid1 = UUID::v5($ns, 'hello');
        $uuid2 = UUID::v5($ns, 'hello');
        self::assertSame($uuid1, $uuid2);
        self::assertTrue(UUID::is_valid($uuid1));
    }

    public function testV5VersionBits(): void
    {
        $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $uuid = UUID::v5($ns, 'test');
        self::assertNotFalse($uuid);
        self::assertSame('5', $uuid[14]); // version 5
    }

    public function testV5InvalidNamespace(): void
    {
        self::assertFalse(UUID::v5('not-a-namespace', 'name'));
    }

    public function testV3AndV5ProduceDifferent(): void
    {
        $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $v3 = UUID::v3($ns, 'test');
        $v5 = UUID::v5($ns, 'test');
        self::assertNotFalse($v3);
        self::assertNotFalse($v5);
        self::assertNotSame($v3, $v5);
    }

    public function testV4cNoDashes(): void
    {
        $uuid = UUID::v4c();
        self::assertSame(32, strlen($uuid));
        self::assertStringNotContainsString('-', $uuid);
    }
}
