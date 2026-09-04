<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\Entity\StoreOrder;
use App\Trade\Entity\Order;
use App\Trade\Repository\OrderRepository;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

readonly class StoreOrderDirectVerifyService
{
    /**
     * StoreOrder statuses allowed for direct verification (after paid, before verified).
     * paid on Trade side corresponds to StoreOrder accepted onwards.
     */
    private const array ALLOWED_STORE_STATUSES = [
        StoreOrder::STATUS_ACCEPTED,
        StoreOrder::STATUS_FULFILLMENT_PENDING,
        StoreOrder::STATUS_FULFILLING,
        StoreOrder::STATUS_FULFILLED,
    ];

    /**
     * Trade Order statuses allowed for direct verification (paid onwards, before completed).
     */
    private const array ALLOWED_TRADE_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FULFILLED,
        Order::STATUS_AWAITING_STORE_VERIFICATION,
    ];

    public function __construct(
        private StoreOrderServiceInterface $storeOrderService,
        private OrderRepository $orderRepository,
        private OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private WorkflowInterface $orderWorkflow,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Direct verification from any status after paid but before verified.
     * Uses order number (StoreOrder uuid) as verification, no code required.
     *
     * @throws \LogicException when already verified or status not allowed
     */
    public function directVerify(StoreOrder $storeOrder, ?string $verifiedBy = null): StoreOrder
    {
        if ($storeOrder->getVerifiedAt() !== null) {
            throw new \LogicException('Store order already verified.');
        }

        $status = $storeOrder->getOperationalStatus();
        if (!in_array($status, self::ALLOWED_STORE_STATUSES, true)) {
            if (in_array($status, [StoreOrder::STATUS_REJECTED, StoreOrder::STATUS_CANCELLED, StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_AWAITING_INVENTORY], true)) {
                throw new \LogicException('Store order cannot be verified in its current status.');
            }
            if (!in_array($status, self::ALLOWED_STORE_STATUSES, true)) {
                throw new \LogicException('Store order cannot be verified in its current status.');
            }
        }

        // Verify StoreOrder (records outbox) - no verificationCode needed, uses order number
        $this->storeOrderService->verify($storeOrder, $verifiedBy);

        // Also try to advance Trade Order synchronously for immediate feedback.
        // This is best-effort and runs in same transaction if possible.
        $this->advanceTradeOrder($storeOrder->getTradeOrderUuid());

        return $storeOrder;
    }

    public function isAllowedStoreStatus(StoreOrder $storeOrder): bool
    {
        if ($storeOrder->getVerifiedAt() !== null) {
            return false;
        }

        return in_array($storeOrder->getOperationalStatus(), self::ALLOWED_STORE_STATUSES, true);
    }

    public function getAllowedTradeStatuses(): array
    {
        return self::ALLOWED_TRADE_STATUSES;
    }

    private function advanceTradeOrder(string $tradeOrderUuid): void
    {
        $order = $this->orderRepository->findOneBy(['uuid' => $tradeOrderUuid]);
        if (!$order instanceof Order) {
            return;
        }

        // If Trade Order is already completed/cancelled/refunded etc, nothing to do
        if (in_array($order->getStatus(), [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            return;
        }

        // If paid -> fulfill (with empty data) to reach fulfilled
        if ($order->getStatus() === Order::STATUS_PAID) {
            try {
                $this->orderService->fulfill($order, []);
                if ($this->orderWorkflow->can($order, 'fulfill')) {
                    $this->orderWorkflow->apply($order, 'fulfill');
                }
                $this->entityManager->flush();
            } catch (\Throwable) {
                // If fulfill fails, still try to proceed
            }
            // Reload status after fulfill
            $order = $this->orderRepository->findOneBy(['uuid' => $tradeOrderUuid]);
            if (!$order instanceof Order) {
                return;
            }
        }

        // fulfilled -> awaiting_store_verification -> completed
        // Use workflow if available, with guard checks
        try {
            if ($this->orderWorkflow->can($order, 'request_verification')) {
                $this->orderWorkflow->apply($order, 'request_verification');
            }
            if ($this->orderWorkflow->can($order, 'store_verify')) {
                $this->orderWorkflow->apply($order, 'store_verify');
            } elseif ($this->orderWorkflow->can($order, 'complete')) {
                // Fallback for orders without verification requirement
                $this->orderWorkflow->apply($order, 'complete');
            }
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Workflow guard may block; ignore for direct verify - store verification is still recorded
        }
    }
}
