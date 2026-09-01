<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Inventory\Message\InventoryReservationConfirmedMessage;
use CrudPlatform\IntegrationContracts\Event\V1\InventoryReservationConfirmed;
use App\Store\Entity\InboxMessage;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\InboxMessageRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Service\StoreOrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InventoryReservationConfirmedHandler
{
    public function __construct(
        private InboxMessageRepository $consumedEventRepository,
        private StoreOrderRepository $storeOrderRepository,
        private StoreOrderServiceInterface $storeOrderService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(InventoryReservationConfirmedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (($message->envelope['type'] ?? null) !== 'inventory.reservation.confirmed'
            || ($message->envelope['version'] ?? null) !== 1
            || !is_string($eventId)
            || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid inventory.reservation.confirmed.v1 envelope.');
        }
        $correlationId = is_string($message->envelope['correlationId'] ?? null)
            ? $message->envelope['correlationId']
            : $eventId;
        foreach (['reservationId', 'storeUuid', 'tradeOrderUuid', 'storeOrderUuid', 'confirmedAt'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new \InvalidArgumentException('Invalid inventory reservation confirmation payload.');
            }
        }
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $correlationId, $payload, $message): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }
            $this->entityManager->persist(new InboxMessage(
                $eventId,
                'inventory.reservation.confirmed.v1',
                $payload['reservationId'],
                hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
            ));

            $storeOrder = $this->storeOrderRepository->findOneByUuid($payload['storeOrderUuid']);
            if ($storeOrder === null
                || $storeOrder->getStore()->getUuid() !== $payload['storeUuid']
                || $storeOrder->getTradeOrderUuid() !== $payload['tradeOrderUuid']
                || $storeOrder->getReservationId() !== $payload['reservationId']
                || $storeOrder->getOperationalStatus() !== StoreOrder::STATUS_AWAITING_INVENTORY) {
                return;
            }

            $this->storeOrderService->accept($storeOrder, $payload['reservationId'], $correlationId, $eventId);
        });
    }

    #[AsMessageHandler(handles: InventoryReservationConfirmed::class)]
    public function handleContract(InventoryReservationConfirmed $message): void
    {
        $this(new InventoryReservationConfirmedMessage($message->envelope));
    }
}
