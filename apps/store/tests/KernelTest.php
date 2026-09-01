<?php

declare(strict_types=1);

namespace App\Store\Tests;

use App\Store\Kernel;
use App\Core\CoreBundle;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testKernelBootsWithoutMonolithSource(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        self::assertSame('App\\Store\\Kernel', $kernel::class);
        self::assertContains(CoreBundle::class, array_map(static fn (object $bundle): string => $bundle::class, $kernel->getBundles()));

        $kernel->shutdown();
    }
}
