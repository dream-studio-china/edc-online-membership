<?php

declare(strict_types=1);

namespace App\Tests\Store\Controller\Staff;

use App\Identity\Entity\User;
use App\Store\Controller\Staff\StoreOrderController;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreMembershipServiceInterface;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreOrderControllerTest extends TestCase
{
    private StoreServiceInterface $storeService;
    private StoreMembershipServiceInterface $membershipService;
    private StoreOrderServiceInterface $orderService;
    private StoreOrderController $controller;
    private Store $store;
    private User $user;

    protected function setUp(): void
    {
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->membershipService = $this->createMock(StoreMembershipServiceInterface::class);
        $this->orderService = $this->createMock(StoreOrderServiceInterface::class);
        $this->controller = new StoreOrderController($this->storeService, $this->membershipService, $this->orderService);
        $this->store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $this->user = new User();
    }

    public function testAcceptActionReturns404WhenStoreIsNotAuthorized(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '00000000-0000-4000-8000-000000000001';
        $request = $this->postRequest('/accept');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        $response = $this->controller->acceptAction($request, $storeUuid, $orderUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order not found or access denied.', $body['message']);
    }

    public function testAcceptActionReturns404WhenOrderNotFound(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '00000000-0000-4000-8000-000000000001';
        $request = $this->postRequest('/accept');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $orderUuid])->willReturn(null);

        $response = $this->controller->acceptAction($request, $storeUuid, $orderUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order not found or access denied.', $body['message']);
    }

    public function testAcceptActionReturns400WhenOrderIsNotInAcceptableStatus(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $order->fulfill();
        $request = $this->postRequest('/accept');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->acceptAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order cannot be accepted in its current status.', $body['message']);
    }

    public function testAcceptActionReturns400WhenReservationIdIsNotAString(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $request = $this->postRequest('/accept', '{"reservationId": ["invalid"]}');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->acceptAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('reservationId must be a string.', $body['message']);
    }

    public function testAcceptActionAcceptsOrder(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $request = $this->postRequest('/accept');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);
        $this->orderService->method('accept')->with($order, null)->willReturn($order);

        $response = $this->controller->acceptAction($request, $storeUuid, $order->getUuid());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Store order accepted.', $body['message']);
    }

    public function testRejectActionReturns404WhenOrderNotFound(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '00000000-0000-4000-8000-000000000001';
        $request = $this->postRequest('/reject');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $orderUuid])->willReturn(null);

        $response = $this->controller->rejectAction($request, $storeUuid, $orderUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order not found or access denied.', $body['message']);
    }

    public function testRejectActionReturns400WhenOrderIsNotInRejectableStatus(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $order->fulfill();
        $request = $this->postRequest('/reject', '{"code":"OUT_OF_STOCK","reason":"Unavailable."}');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->rejectAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order cannot be rejected in its current status.', $body['message']);
    }

    public function testRejectActionReturns400WhenCodeOrReasonMissing(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $request = $this->postRequest('/reject');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->rejectAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('code and reason are required.', $body['message']);
    }

    public function testRejectActionRejectsOrder(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $request = $this->postRequest('/reject', '{"code":"OUT_OF_STOCK","reason":"Unavailable."}');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);
        $this->orderService->method('reject')->with($order, 'OUT_OF_STOCK', 'Unavailable.')->willReturn($order);

        $response = $this->controller->rejectAction($request, $storeUuid, $order->getUuid());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order rejected.', $body['message']);
    }

    public function testFulfillActionReturns404WhenOrderNotFound(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '00000000-0000-4000-8000-000000000001';
        $request = $this->postRequest('/fulfill');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $orderUuid])->willReturn(null);

        $response = $this->controller->fulfillAction($request, $storeUuid, $orderUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order not found or access denied.', $body['message']);
    }

    public function testFulfillActionReturns400WhenOrderIsNotInFulfillableStatus(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $request = $this->postRequest('/fulfill');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->fulfillAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order cannot be fulfilled in its current status.', $body['message']);
    }

    public function testFulfillActionReturns400WhenFulfillmentDataIsNotAnObject(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $order->accept();
        $request = $this->postRequest('/fulfill', '{"fulfillmentData": "not-an-object"}');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);

        $response = $this->controller->fulfillAction($request, $storeUuid, $order->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('fulfillmentData must be an object.', $body['message']);
    }

    public function testFulfillActionFulfillsOrder(): void
    {
        $storeUuid = $this->store->getUuid();
        $order = $this->order();
        $order->accept();
        $request = $this->postRequest('/fulfill', '{"fulfillmentData": {"mode": "pickup"}}');
        $this->injectDependencies($request);

        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);
        $this->orderService->method('get')->with(['uuid' => $order->getUuid()])->willReturn($order);
        $this->orderService->method('fulfill')->with($order, ['mode' => 'pickup'])->willReturn($order);

        $response = $this->controller->fulfillAction($request, $storeUuid, $order->getUuid());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store order fulfilled.', $body['message']);
    }

    public function testScopedListFilterUsesAuthorizedStore(): void
    {
        $storeUuid = $this->store->getUuid();
        $this->injectDependencies($this->postRequest('/list'));
        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);

        self::assertSame(['store' => $this->store], $this->invokeScoped('scopedListFilter', $storeUuid));
    }

    public function testScopedListFilterDeniesWhenStoreIsNotAuthorized(): void
    {
        $storeUuid = $this->store->getUuid();
        $this->injectDependencies($this->postRequest('/list'));
        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        self::assertSame(['id' => -1], $this->invokeScoped('scopedListFilter', $storeUuid));
    }

    public function testScopedDetailFilterUsesAuthorizedStore(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '2beed699-4e1b-4a49-af75-2e0b0f6db0fd';
        $this->injectDependencies($this->postRequest('/detail'));
        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn($this->store);
        $this->membershipService->method('isAuthorized')->willReturn(true);

        self::assertSame(['store' => $this->store, 'uuid' => $orderUuid], $this->invokeScoped('scopedDetailFilter', $storeUuid, $orderUuid));
    }

    public function testScopedDetailFilterDeniesWhenStoreIsNotAuthorized(): void
    {
        $storeUuid = $this->store->getUuid();
        $orderUuid = '2beed699-4e1b-4a49-af75-2e0b0f6db0fd';
        $this->injectDependencies($this->postRequest('/detail'));
        $this->storeService->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        self::assertSame(['id' => -1], $this->invokeScoped('scopedDetailFilter', $storeUuid, $orderUuid));
    }

    /** @return array<string, mixed> */
    private function invokeScoped(string $method, string ...$args): array
    {
        return (new \ReflectionMethod(StoreOrderController::class, $method))->invoke($this->controller, ...$args);
    }

    private function order(): StoreOrder
    {
        return new StoreOrder(
            $this->store,
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'xuhui',
            'Xuhui',
            $this->user->getUuid(),
            'CNY',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-24T12:00:00+00:00'],
        );
    }

    private function postRequest(string $path, string $content = '{}'): Request
    {
        return Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }

    private function injectDependencies(Request $request): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'security.token_storage' => $tokenStorage,
            default => null,
        });

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
        $this->controller->setContainer($container);
    }
}
