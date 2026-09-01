<?php

declare(strict_types=1);

namespace App\Trade\Tests;

use App\Trade\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

final class KernelTest extends TestCase
{
    public function testKernelBootsAndRegistersTradeAndPromotionRoutes(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame('/api/v1/app/orders', $router->getRouteCollection()->get('app-orders-create')?->getPath());
        self::assertSame('/api/v1/app/promotions', $router->getRouteCollection()->get('app-promotions-list')?->getPath());
        $kernel->shutdown();
    }
}
