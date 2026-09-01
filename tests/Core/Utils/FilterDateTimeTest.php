<?php

namespace App\Tests\Core\Utils;

use App\Core\Utils\FilterDateTime;
use PHPUnit\Framework\TestCase;

final class FilterDateTimeTest extends TestCase
{
    public function testGetReturnsDateTimeInstance(): void
    {
        $value = (new FilterDateTime())->get('2024-01-01 00:00:00', new \DateTimeZone('UTC'));

        self::assertInstanceOf(\DateTime::class, $value);
        self::assertSame('UTC', $value->getTimezone()->getName());
    }
}
