<?php

declare(strict_types=1);

namespace App\Wallet\Tests;

use App\Wallet\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

final class KernelTest extends TestCase
{
    public function testKernelBootsAndKeepsWalletRoutes(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertSame('/api/v1/app/wallets', $router->getRouteCollection()->get('app-wallets-list')?->getPath());
        self::assertSame('/api/v1/manage/wallets', $router->getRouteCollection()->get('manage-wallets-list')?->getPath());
        $kernel->shutdown();
    }
}
