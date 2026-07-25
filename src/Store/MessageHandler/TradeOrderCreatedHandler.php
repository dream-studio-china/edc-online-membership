<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Store\Entity\StoreConsumedEvent;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxService;
use App\Trade\Message\TradeOrderCreatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TradeOrderCreatedHandler
{
    public function __construct(
        private StoreRepository $storeRepository,
        private StoreConsumedEventRepository $consumedEventRepository,
        private StoreOrderServiceInterface $storeOrderService,
        private StoreOutboxService $outboxService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TradeOrderCreatedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (!is_string($eventId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid trade.order.created.v1 envelope.');
        }
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $storeSnapshot = $payload['store'] ?? null;
        $storeUuid = is_array($storeSnapshot) ? ($storeSnapshot['uuid'] ?? null) : null;
        if (!is_string($storeUuid)) {
            throw new \InvalidArgumentException('Trade order event does not include a store UUID.');
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $message, $payload, $storeUuid): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }

            $encoded = json_encode($message->envelope, JSON_THROW_ON_ERROR);
            $this->entityManager->persist(new StoreConsumedEvent(
                $eventId,
                'trade.order.created.v1',
                (string) ($payload['orderUuid'] ?? ''),
                hash('sha256', $encoded),
            ));

            $store = $this->storeRepository->findOneByUuid($storeUuid);
            if ($store === null || !$store->isActive()) {
                $this->recordRejected($payload, $storeUuid, 'STORE_UNAVAILABLE', 'Store is not available.');
                return;
            }

            $storeOrder = $this->storeOrderService->createFromTradeOrderSnapshot($store, $payload);
            if ($storeOrder->getOperationalStatus() === \App\Store\Entity\StoreOrder::STATUS_PENDING_VALIDATION) {
                $this->storeOrderService->accept($storeOrder);
            }
        });
    }

    /** @param array<string, mixed> $payload */
    private function recordRejected(array $payload, string $storeUuid, string $code, string $reason): void
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
        ]);
    }
}
