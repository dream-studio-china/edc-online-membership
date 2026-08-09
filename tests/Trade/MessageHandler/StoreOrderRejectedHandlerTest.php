<?php

declare(strict_types=1);

namespace App\Tests\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderRejectedMessage;
use App\Trade\MessageHandler\StoreOrderRejectedHandler;
use App\Trade\Service\OrderService;
use App\Trade\Service\OrderServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Workflow\WorkflowInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreOrderRejectedHandlerTest extends TestCase
{
    private const STORE_UUID = '00000000-0000-4000-8000-000000000040';
    private const ORDER_UUID = '00000000-0000-4000-8000-000000000041';

    public function testRejectsMissingEnvelopePayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid store.order.rejected.v1 envelope.');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage([]));
    }

    public function testRejectsNonArrayPayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => 42]));
    }

    public function testIgnoresMessageWhenOrderIsNotFound(): void
    {
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->with(['uuid' => self::ORDER_UUID])->willReturn(null);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

    public function testIgnoresMessageWhenStoreUuidDoesNotMatch(): void
    {
        $order = (new Order())->setMetadata(['_store' => ['uuid' => '00000000-0000-4000-8000-000000000001']]);
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn($order);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

    public function testIgnoresMessageWhenOrderHasNoStoreMetadata(): void
    {
        $order = new Order();
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn($order);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

    public function testDoesNotApplyWhenWorkflowCannotReject(): void
    {
        $order = (new Order())->setMetadata(['_store' => ['uuid' => self::STORE_UUID]]);
        $orders = $this->createStub(OrderService::class);
        $orders->method('get')->willReturn($order);
        $orders->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'store_reject')->willReturn(false);
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

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
}
