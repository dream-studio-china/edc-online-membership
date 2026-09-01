<?php

declare(strict_types=1);

namespace App\Tests\Trade\Controller\Manage;

use App\Trade\Controller\Manage\OrderController;
use App\Core\Security\UserUuidResolverInterface;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class OrderControllerTest extends TestCase
{
    private OrderServiceInterface $service;
    private WorkflowInterface $workflow;
    private StoreContextResolverInterface $storeContextResolver;
    private UserUuidResolverInterface $userUuidResolver;
    private OrderController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(OrderServiceInterface::class);
        $this->workflow = $this->createMock(WorkflowInterface::class);
        $this->storeContextResolver = $this->createMock(StoreContextResolverInterface::class);
        $this->userUuidResolver = $this->createMock(UserUuidResolverInterface::class);

        $this->controller = new OrderController($this->service, $this->storeContextResolver, $this->userUuidResolver, $this->workflow);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    public function testDeleteActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999', 'DELETE'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->deleteAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testPayActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/pay', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/pay', 'POST');
        $response = $this->controller->payAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testPayActionReturnsErrorWhenCannotPay(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/pay', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('draft');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(false);

        $request = Request::create('/api/v1/manage/orders/1/pay', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['systemWalletId' => 0]));

        $response = $this->controller->payAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order cannot be paid in current status.', $body['message']);
    }

    public function testPaymentActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/payment', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/payment', 'POST');
        $response = $this->controller->paymentAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testPaymentActionReturnsErrorWhenCannotPay(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/payment', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('draft');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(false);

        $request = Request::create('/api/v1/manage/orders/1/payment', 'POST');
        $response = $this->controller->paymentAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order cannot be paid in current status.', $body['message']);
    }

    public function testFulfillActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/fulfill', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/fulfill', 'POST');
        $response = $this->controller->fulfillAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testRefundActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/refund', 'POST');
        $response = $this->controller->refundAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testTransitionsActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/transitions', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->transitionsAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testDoTransitionActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/do/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/do/cancel', 'POST');
        $response = $this->controller->doTransitionAction($request, 999, 'cancel');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testCreateActionReturnsErrorWhenItemsEmpty(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders', 'POST'));
        $this->injectDependencies($requestStack);

        $request = Request::create('/api/v1/manage/orders', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['items' => []]));

        $response = $this->controller->createAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Items are required.', $body['message']);
    }

    public function testRefundActionReturnsErrorWhenReasonMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'refund')->willReturn(true);

        $request = Request::create('/api/v1/manage/orders/1/refund', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['reason' => '']));

        $response = $this->controller->refundAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('reason is required.', $body['message']);
    }

    public function testItemsActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/items', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->itemsAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testUpdateActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999', 'PUT'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999', 'PUT', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        $response = $this->controller->updateAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testDeleteActionReturnsErrorWhenNotDraft(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1', 'DELETE'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);

        $response = $this->controller->deleteAction(1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Only draft orders can be deleted.', $body['message']);
    }

    public function testUpdateActionReturnsErrorWhenNotDraft(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1', 'PUT'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);

        $request = Request::create('/api/v1/manage/orders/1', 'PUT', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        $response = $this->controller->updateAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Only draft orders can be updated.', $body['message']);
    }
}
