<?php

declare(strict_types=1);

namespace App\Payment\Tests;

use App\Payment\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

final class KernelTest extends TestCase
{
    public function testKernelBoots(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        self::assertSame('test', $kernel->getEnvironment());

        $kernel->shutdown();
    }

    public function testRoutesKeepTheGatewayApiPrefix(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertSame('/api/v1/manage/invoices', $router->getRouteCollection()->get('manage-invoices-create')?->getPath());
        self::assertSame('/api/payment/notify/{payment}', $router->getRouteCollection()->get('payment-notify')?->getPath());

        $kernel->shutdown();
    }
}
