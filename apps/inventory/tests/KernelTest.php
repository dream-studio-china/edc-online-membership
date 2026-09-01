<?php

declare(strict_types=1);

namespace App\Inventory\Tests;

use App\Inventory\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testKernelBoots(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        self::assertSame('test', $kernel->getEnvironment());

        $kernel->shutdown();
    }
}
