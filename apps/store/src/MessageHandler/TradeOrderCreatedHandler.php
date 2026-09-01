<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Store\Entity\InboxMessage;
use App\Store\Repository\InboxMessageRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\TradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\OutboxService;
use App\Trade\Message\TradeOrderCreatedMessage;
use CrudPlatform\IntegrationContracts\Event\V1\TradeOrderCreated;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TradeOrderCreatedHandler
{
    public function __construct(
        private StoreRepository $storeRepository,
        private InboxMessageRepository $consumedEventRepository,
        private TradeOrderCancellationRepository $cancellationRepository,
        private StoreOrderServiceInterface $storeOrderService,
        private OutboxService $outboxService,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(bool:INVENTORY_ENABLED)%')]
        private bool $inventoryEnabled = false,
    ) {
    }

    public function __invoke(TradeOrderCreatedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (!is_string($eventId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid trade.order.created.v1 envelope.');
        }
        $correlationId = is_string($message->envelope['correlationId'] ?? null)
            ? $message->envelope['correlationId']
            : $eventId;
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $storeSnapshot = $payload['store'] ?? null;
        $storeUuid = is_array($storeSnapshot) ? ($storeSnapshot['uuid'] ?? null) : null;
        if (!is_string($storeUuid)) {
            throw new \InvalidArgumentException('Trade order event does not include a store UUID.');
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $correlationId, $message, $payload, $storeUuid): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }

            $encoded = json_encode($message->envelope, JSON_THROW_ON_ERROR);
            $this->entityManager->persist(new InboxMessage(
                $eventId,
                'trade.order.created.v1',
                (string) ($payload['orderUuid'] ?? ''),
                hash('sha256', $encoded),
            ));

            $cancellation = $this->cancellationRepository->findOneByTradeOrderUuid((string) ($payload['orderUuid'] ?? ''));
            if ($cancellation !== null && $cancellation->getStoreUuid() !== $storeUuid) {
                throw new \LogicException('Trade order cancellation conflicts with the Store order snapshot.');
            }

            $store = $this->storeRepository->findOneByUuid($storeUuid);
            if ($store === null || !$store->isActive()) {
                $this->recordRejected($payload, $storeUuid, 'STORE_UNAVAILABLE', 'Store is not available.', $correlationId, $eventId);
                return;
            }

            $storeOrder = $this->storeOrderService->createFromTradeOrderSnapshot($store, $payload);
            if ($cancellation !== null) {
                $storeOrder->cancel();
                return;
            }
            if ($storeOrder->getOperationalStatus() !== \App\Store\Entity\StoreOrder::STATUS_PENDING_VALIDATION) {
                return;
            }

            if (!$this->inventoryEnabled) {
                $this->storeOrderService->accept($storeOrder, null, $correlationId, $eventId);
                return;
            }

            $reservationId = \App\Core\Utils\UUID::v4();
            $storeOrder->awaitInventory($reservationId);
            $this->outboxService->record('inventory.reservation.requested.v1', 'inventory_reservation', $reservationId, [
                'reservationId' => $reservationId,
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'tradeOrderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'items' => $this->inventoryItems($payload),
                'expiresAt' => (new \DateTimeImmutable('+30 minutes'))->format(DATE_ATOM),
                'requestedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $correlationId, $eventId);
        });
    }

    #[AsMessageHandler(handles: TradeOrderCreated::class)]
    public function handleContract(TradeOrderCreated $message): void
    {
        $this(new TradeOrderCreatedMessage($message->envelope));
    }

    /** @param array<string, mixed> $payload */
    private function recordRejected(
        array $payload,
        string $storeUuid,
        string $code,
        string $reason,
        string $correlationId,
        string $causationId,
    ): void
    {
        $orderUuid = $payload['orderUuid'] ?? null;
        if (!is_string($orderUuid)) {
            throw new \InvalidArgumentException('Trade order event does not include an order UUID.');
        }
        $this->outboxService->record('store.order.rejected.v1', 'trade_order', $orderUuid, [
            'orderUuid' => $orderUuid,
            'storeOrderUuid' => null,
            'storeUuid' => $storeUuid,
            'reasonCode' => $code,
            'reason' => $reason,
            'rejectedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], $correlationId, $causationId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{lineId: string, catalogReference: string, quantity: string}>
     */
    private function inventoryItems(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Trade order event does not include inventory items.');
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_string($item['lineId'] ?? null)
                || !is_string($item['catalogReference'] ?? null)
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] <= 0) {
                throw new \InvalidArgumentException('Trade order event includes an invalid inventory item.');
            }
            $result[] = [
                'lineId' => $item['lineId'],
                'catalogReference' => $item['catalogReference'],
                'quantity' => sprintf('%d.000000', $item['quantity']),
            ];
        }

        return $result;
    }
}
