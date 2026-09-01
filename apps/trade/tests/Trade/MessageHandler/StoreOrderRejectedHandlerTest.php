<?php

declare(strict_types=1);

namespace App\Tests\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderRejectedMessage;
use App\Trade\MessageHandler\StoreOrderRejectedHandler;
use App\Trade\Service\OrderService;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderRejected;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

final class StoreOrderRejectedHandlerTest extends TestCase
{
    public function testStoreRejectionDoesNotTransitionTheOrderToCancelled(): void
    {
        $order = (new Order())->setStatus('awaiting_store_acceptance')->setMetadata(['_store' => ['uuid' => '00000000-0000-4000-8000-000000000040']]);
        $orders = $this->createMock(OrderService::class);
        $orders->method('get')->willReturn($order);
        $orders->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'store_reject')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'store_reject');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => '00000000-0000-4000-8000-000000000040',
        ]]));
    }

    public function testContractCarrierUsesTheExistingRejectionFlow(): void
    {
        $order = (new Order())->setStatus('awaiting_store_acceptance')->setMetadata(['_store' => ['uuid' => '00000000-0000-4000-8000-000000000040']]);
        $orders = $this->createMock(OrderService::class);
        $orders->method('get')->willReturn($order);
        $orders->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'store_reject')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'store_reject');

        (new StoreOrderRejectedHandler($orders, $workflow))->handleContract(new StoreOrderRejected(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => '00000000-0000-4000-8000-000000000040',
        ]]));
    }
}
