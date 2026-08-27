<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\MessageHandler\StoreOrderAcceptedHandler;
use App\Trade\Service\OrderServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

final class StoreOrderAcceptedHandlerTest extends TestCase
{
    private const STORE_UUID = '00000000-0000-4000-8000-000000000099';
    private const ORDER_UUID = '00000000-0000-4000-8000-000000000098';

    public function testRejectsMissingEnvelopePayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid store.order.accepted.v1 envelope.');

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage([]));
    }

    public function testRejectsNonArrayPayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => 'not-an-array']));
    }

    public function testRejectsMissingOrderUuid(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => ['storeUuid' => self::STORE_UUID]]));
    }

    public function testIgnoresMessageWhenOrderIsNotFound(): void
    {
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->with(['uuid' => self::ORDER_UUID])->willReturn(null);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('can');
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => [
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

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => [
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

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

    public function testDoesNotApplyWhenWorkflowCannotAccept(): void
    {
        $order = (new Order())->setMetadata(['_store' => ['uuid' => self::STORE_UUID]]);
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn($order);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'store_accept')->willReturn(false);
        $workflow->expects(self::never())->method('apply');

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }

    public function testAppliesStoreAcceptTransitionWithinTransaction(): void
    {
        $order = (new Order())->setMetadata(['_store' => ['uuid' => self::STORE_UUID]]);
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn($order);
        $orders->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback()
        );
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'store_accept')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'store_accept');

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => self::STORE_UUID,
        ]]));
    }
}
