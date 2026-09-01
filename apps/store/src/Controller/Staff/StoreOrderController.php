<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ScopedDetailApiViewMixin;
use App\Core\View\ScopedListApiViewMixin;
use App\Core\Security\UserUuidPrincipalInterface;
use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Entity\StoreOrder;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/stores/{scopeId}/orders', name: 'store-orders-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class StoreOrderController extends RestController
{
    use ApiView, ScopedListApiViewMixin, ScopedDetailApiViewMixin;

    public function __construct(
        private readonly StoreServiceInterface $storeService,
        private readonly MembershipServiceInterface $membershipService,
        protected readonly StoreOrderServiceInterface $service,
    ) {
    }

    /** @return array<string, mixed> */
    protected function scopedListFilter(string $scopeId): array
    {
        $store = $this->authorizedStore($scopeId);

        return $store === null ? ['id' => -1] : ['store' => $store];
    }

    /** @return array<string, mixed> */
    protected function scopedDetailFilter(string $scopeId, string $id): array
    {
        $store = $this->authorizedStore($scopeId);

        return $store === null ? ['id' => -1] : ['store' => $store, 'uuid' => $id];
    }

    #[Route('/{orderUuid}/accept', name: 'accept', methods: ['POST'], requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
    public function acceptAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $order = $this->authorizedOrder($scopeId, $orderUuid, [Membership::ROLE_OWNER, Membership::ROLE_MANAGER, Membership::ROLE_CLERK]);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_AWAITING_INVENTORY], true)) {
            return $this->warning('Store order cannot be accepted in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        $reservationId = $data['reservationId'] ?? null;
        if ($reservationId !== null && !is_string($reservationId)) {
            return $this->warning('reservationId must be a string.', 400, '', 400);
        }
        $this->service->accept($order, $reservationId);

        return $this->success($order, 'Store order accepted.');
    }

    #[Route('/{orderUuid}/reject', name: 'reject', methods: ['POST'], requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
    public function rejectAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $order = $this->authorizedOrder($scopeId, $orderUuid, [Membership::ROLE_OWNER, Membership::ROLE_MANAGER, Membership::ROLE_CLERK]);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_AWAITING_INVENTORY], true)) {
            return $this->warning('Store order cannot be rejected in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        if (!is_string($data['code'] ?? null) || trim($data['code']) === '' || !is_string($data['reason'] ?? null) || trim($data['reason']) === '') {
            return $this->warning('code and reason are required.', 400, '', 400);
        }
        $this->service->reject($order, $data['code'], $data['reason']);

        return $this->success($order, 'Store order rejected.');
    }

    #[Route('/{orderUuid}/fulfill', name: 'fulfill', methods: ['POST'], requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
    public function fulfillAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $order = $this->authorizedOrder($scopeId, $orderUuid, [Membership::ROLE_OWNER, Membership::ROLE_MANAGER, Membership::ROLE_FULFILLMENT]);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_ACCEPTED, StoreOrder::STATUS_FULFILLMENT_PENDING, StoreOrder::STATUS_FULFILLING], true)) {
            return $this->warning('Store order cannot be fulfilled in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        $fulfillmentData = $data['fulfillmentData'] ?? null;
        if ($fulfillmentData !== null && !is_array($fulfillmentData)) {
            return $this->warning('fulfillmentData must be an object.', 400, '', 400);
        }
        $this->service->fulfill($order, $fulfillmentData);

        return $this->success($order, 'Store order fulfilled.');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    /** @param list<string> $roles */
    private function authorizedStore(string $storeUuid, array $roles = []): ?Store
    {
        $store = $this->storeService->get(['uuid' => $storeUuid]);
        $user = $this->getUser();
        if (!$store instanceof Store || !$user instanceof UserUuidPrincipalInterface) {
            return null;
        }

        return $this->membershipService->isAuthorized($store, $user->getUuid(), $roles) ? $store : null;
    }

    /** @param list<string> $roles */
    private function authorizedOrder(string $storeUuid, string $orderUuid, array $roles = []): ?StoreOrder
    {
        $store = $this->authorizedStore($storeUuid, $roles);
        if ($store === null) {
            return null;
        }

        $order = $this->service->get(['uuid' => $orderUuid]);
        return $order instanceof StoreOrder && $order->getStore()->getUuid() === $store->getUuid() ? $order : null;
    }
}
