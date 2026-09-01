<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\InventoryLedgerEntry;
use App\Inventory\Entity\InventoryReservation;
use App\Inventory\Entity\InventoryStock;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\ReservationLine;
use App\Inventory\Repository\InventoryLedgerEntryRepository;
use App\Inventory\Repository\InventoryReservationRepository;
use App\Inventory\Repository\InventoryStockRepository;
use App\Inventory\Repository\MaterialRepository;
use App\Inventory\Repository\SpecificationRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InventoryService implements InventoryServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaterialRepository $materialRepository,
        private readonly InventoryStockRepository $stockRepository,
        private readonly SpecificationRecipeRepository $recipeRepository,
        private readonly InventoryReservationRepository $reservationRepository,
        private readonly InventoryLedgerEntryRepository $ledgerRepository,
        private readonly InventoryOutboxService $outbox,
    ) {
    }

    /** @return array{storeUuid: string, materialUuid: string, exists: bool, onHandQuantity: string, reservedQuantity: string, availableQuantity: string, allowNegativeStock: bool} */
    public function getStockView(string $storeUuid, string $materialUuid): array
    {
        $this->assertStoreUuid($storeUuid);
        $material = $this->materialRepository->findOneByUuid($materialUuid);
        if ($material === null) {
            throw new \InvalidArgumentException('Material was not found.');
        }

        $stock = $this->stockRepository->findOneByStoreAndMaterial($storeUuid, $material);
        if ($stock === null) {
            return [
                'storeUuid' => $storeUuid,
                'materialUuid' => $materialUuid,
                'exists' => false,
                'onHandQuantity' => Quantity::ZERO,
                'reservedQuantity' => Quantity::ZERO,
                'availableQuantity' => Quantity::ZERO,
                'allowNegativeStock' => false,
            ];
        }

        return [
            'storeUuid' => $storeUuid,
            'materialUuid' => $materialUuid,
            'exists' => true,
            'onHandQuantity' => $stock->getOnHandQuantity(),
            'reservedQuantity' => $stock->getReservedQuantity(),
            'availableQuantity' => $stock->getAvailableQuantity(),
            'allowNegativeStock' => $stock->allowsNegativeStock(),
        ];
    }

    public function adjustStock(
        string $storeUuid,
        string $materialUuid,
        string $quantityDelta,
        string $reason,
        ?string $referenceId = null,
        ?string $actorReference = null,
        ?bool $allowNegativeStock = null,
    ): InventoryStock
    {
        $this->assertStoreUuid($storeUuid);
        $quantityDelta = Quantity::normalize($quantityDelta);
        if ($quantityDelta === Quantity::ZERO || trim($reason) === '') {
            throw new \InvalidArgumentException('Adjustment quantity and reason are required.');
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $storeUuid,
            $materialUuid,
            $quantityDelta,
            $reason,
            $referenceId,
            $actorReference,
            $allowNegativeStock,
        ): InventoryStock {
            // The material lock serializes first-stock creation, where no stock row exists to lock yet.
            $material = $this->materialRepository->findOneByUuidForUpdate($materialUuid);
            if ($material === null || !$material->isActive()) {
                throw new \LogicException('Only active materials can be adjusted.');
            }

            $stock = $this->stockRepository->findOneByStoreAndMaterialForUpdate($storeUuid, $material);
            if (
                $referenceId !== null
                && $this->ledgerRepository->findOneByOperation(InventoryLedgerEntry::TYPE_ADJUSTMENT, $referenceId, $storeUuid, $material) !== null
            ) {
                if ($stock === null) {
                    throw new \LogicException('Adjustment ledger does not have a stock balance.');
                }

                return $stock;
            }

            if ($stock === null) {
                $stock = new InventoryStock($storeUuid, $material, $allowNegativeStock ?? false);
                $this->entityManager->persist($stock);
            } elseif ($allowNegativeStock !== null) {
                $stock->setAllowNegativeStock($allowNegativeStock);
            }

            $after = Quantity::add($stock->getOnHandQuantity(), $quantityDelta);
            if (!$stock->allowsNegativeStock() && Quantity::compare($after, $stock->getReservedQuantity()) < 0) {
                throw new \LogicException('Adjustment would make confirmed reservations unavailable.');
            }

            $operationReference = $referenceId ?? sprintf('adjustment:%s', \App\Core\Utils\UUID::v4());
            $stock->adjustOnHand($quantityDelta);
            $material->markStockMutated();
            $this->entityManager->persist(new InventoryLedgerEntry(
                $stock,
                InventoryLedgerEntry::TYPE_ADJUSTMENT,
                $quantityDelta,
                Quantity::ZERO,
                'adjustment',
                $operationReference,
                $actorReference,
                $reason,
            ));
            return $stock;
        });
    }

    public function setStockAllowNegative(string $storeUuid, string $materialUuid, bool $allowNegativeStock): InventoryStock
    {
        $this->assertStoreUuid($storeUuid);
        if (!$allowNegativeStock) {
            $material = $this->materialRepository->findOneByUuid($materialUuid);
            if ($material === null) {
                throw new \InvalidArgumentException('Material was not found.');
            }
            $existingStock = $this->stockRepository->findOneByStoreAndMaterial($storeUuid, $material);
            if ($existingStock !== null && Quantity::compare($existingStock->getAvailableQuantity(), Quantity::ZERO) < 0) {
                throw new \LogicException('Negative stock cannot be disabled while confirmed reservations exceed on-hand quantity.');
            }
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $storeUuid,
            $materialUuid,
            $allowNegativeStock,
        ): InventoryStock {
            // The material lock serializes first-stock creation, where no stock row exists to lock yet.
            $material = $this->materialRepository->findOneByUuidForUpdate($materialUuid);
            if ($material === null) {
                throw new \InvalidArgumentException('Material was not found.');
            }

            $stock = $this->stockRepository->findOneByStoreAndMaterialForUpdate($storeUuid, $material);
            if ($stock === null) {
                $stock = new InventoryStock($storeUuid, $material, $allowNegativeStock);
                $this->entityManager->persist($stock);
            } else {
                $stock->setAllowNegativeStock($allowNegativeStock);
            }

            return $stock;
        });
    }

    /** @param list<array{lineId: string, catalogReference: string, quantity: string}> $items */
    public function reserve(
        string $reservationId,
        string $storeUuid,
        string $tradeOrderUuid,
        string $storeOrderUuid,
        array $items,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): InventoryReservation
    {
        $this->assertStoreUuid($storeUuid);
        $normalizedItems = $this->normalizeItems($items);
        $hash = hash('sha256', json_encode([
            'reservationId' => $reservationId,
            'storeUuid' => $storeUuid,
            'tradeOrderUuid' => $tradeOrderUuid,
            'storeOrderUuid' => $storeOrderUuid,
            'items' => $normalizedItems,
            'expiresAt' => $expiresAt?->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
        return $this->entityManager->wrapInTransaction(function () use (
            $reservationId,
            $storeUuid,
            $tradeOrderUuid,
            $storeOrderUuid,
            $normalizedItems,
            $expiresAt,
            $hash,
            $correlationId,
            $causationId,
        ): InventoryReservation {
            $existing = $this->reservationRepository->findOneByReservationId($reservationId);
            if ($existing !== null) {
                if (!hash_equals($existing->getRequestHash(), $hash)) {
                    throw new InventoryReservationConflictException('Reservation ID was reused with a different request.');
                }

                return $existing;
            }
            if ($this->reservationRepository->findOneByStoreOrderUuid($storeOrderUuid) !== null) {
                throw new InventoryReservationConflictException('Store order already has a reservation.');
            }

            $reservation = new InventoryReservation($reservationId, $storeUuid, $tradeOrderUuid, $storeOrderUuid, $hash, $expiresAt);
            $this->entityManager->persist($reservation);
            if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable()) {
                return $this->reject($reservation, 'RESERVATION_EXPIRED', 'The reservation request has expired.', $correlationId, $causationId);
            }

            try {
                $demands = $this->resolveDemands($normalizedItems);
            } catch (ReservationRejected $exception) {
                return $this->reject($reservation, $exception->reasonCode, $exception->getMessage(), $correlationId, $causationId);
            }

            /** @var array<string, InventoryStock> $stocks */
            $stocks = [];
            foreach ($demands as $materialUuid => $demand) {
                $stock = $this->stockRepository->findOneByStoreAndMaterialForUpdate($storeUuid, $demand['material']);
                if (
                    $stock === null
                    || (!$stock->allowsNegativeStock() && Quantity::compare($stock->getAvailableQuantity(), $demand['quantity']) < 0)
                ) {
                    return $this->reject($reservation, 'OUT_OF_STOCK', 'One or more required materials are unavailable.', $correlationId, $causationId);
                }

                $stocks[$materialUuid] = $stock;
            }
            foreach ($demands as $materialUuid => $demand) {
                $stock = $stocks[$materialUuid];
                $stock->reserve($demand['quantity']);
                $demand['material']->markStockMutated();
                $reservation->addLine(new ReservationLine(
                    $demand['material'],
                    $demand['quantity'],
                    $demand['specifications'],
                ));
                $this->entityManager->persist(new InventoryLedgerEntry(
                    $stock,
                    InventoryLedgerEntry::TYPE_RESERVATION,
                    Quantity::ZERO,
                    $demand['quantity'],
                    'reservation',
                    $reservationId,
                ));
            }
            $reservation->confirm();
            $this->outbox->record('inventory.reservation.confirmed.v1', 'inventory_reservation', $reservationId, [
                'reservationId' => $reservationId,
                'storeUuid' => $storeUuid,
                'tradeOrderUuid' => $tradeOrderUuid,
                'storeOrderUuid' => $storeOrderUuid,
                'confirmedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $correlationId, $causationId);
            return $reservation;
        });
    }

    public function release(
        string $reservationId,
        ?string $reason = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): InventoryReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservationId, $reason, $correlationId, $causationId): InventoryReservation {
            $reservation = $this->reservationRepository->findOneByReservationId($reservationId);
            if ($reservation === null) {
                throw new \InvalidArgumentException('Reservation was not found.');
            }
            if (!$reservation->release()) {
                return $reservation;
            }

            foreach ($reservation->getLines() as $line) {
                $material = $this->materialRepository->findOneByUuid($line->getMaterialUuid());
                if ($material === null) {
                    throw new \LogicException('Reservation material was not found.');
                }

                $stock = $this->stockRepository->findOneByStoreAndMaterialForUpdate($reservation->getStoreUuid(), $material);
                if ($stock === null) {
                    throw new \LogicException('Reservation stock was not found.');
                }

                $quantity = $line->getReservedQuantity();
                $stock->release($quantity);
                $this->entityManager->persist(new InventoryLedgerEntry(
                    $stock,
                    InventoryLedgerEntry::TYPE_RELEASE,
                    Quantity::subtract(Quantity::ZERO, Quantity::ZERO),
                    Quantity::subtract(Quantity::ZERO, $quantity),
                    'reservation',
                    $reservationId,
                    null,
                    $reason,
                ));
            }
            $this->outbox->record('inventory.reservation.released.v1', 'inventory_reservation', $reservationId, [
                'reservationId' => $reservationId,
                'releasedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $correlationId, $causationId);
            return $reservation;
        });
    }

    public function releaseExpiredReservations(): int
    {
        $released = 0;
        foreach ($this->reservationRepository->findExpiredConfirmed(new \DateTimeImmutable()) as $reservation) {
            $this->release($reservation->getReservationId(), 'reservation expired');
            ++$released;
        }

        return $released;
    }

    /**
     * @param list<array{lineId: string, catalogReference: string, quantity: string}> $items
     * @return list<array{lineId: string, catalogReference: string, quantity: string}>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Reservation requires at least one item.');
        }

        $lineIds = [];
        $normalized = [];
        foreach ($items as $item) {
            if (
                !isset($item['lineId'], $item['catalogReference'], $item['quantity'])
                || isset($lineIds[$item['lineId']])
            ) {
                throw new \InvalidArgumentException('Reservation items require unique line IDs.');
            }

            $lineIds[$item['lineId']] = true;
            $normalized[] = [
                'lineId' => $item['lineId'],
                'catalogReference' => $item['catalogReference'],
                'quantity' => Quantity::normalize($item['quantity'], true),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['lineId'] <=> $b['lineId']);

        return $normalized;
    }

    /**
     * @param list<array{lineId: string, catalogReference: string, quantity: string}> $items
     * @return array<string, array{material: Material, quantity: string, specifications: list<string>}>
     */
    private function resolveDemands(array $items): array
    {
        $demands = [];
        foreach ($items as $item) {
            $recipe = $this->recipeRepository->findActiveBySpecificationUuid($item['catalogReference']);
            if ($recipe === null) {
                $material = $this->materialRepository->findActiveFinishedByCode($item['catalogReference']);
                if ($material === null) {
                    throw new ReservationRejected('SPECIFICATION_NOT_STOCKABLE', 'The specification is not inventory stockable.');
                }

                $this->addDemand($demands, $material, $item['quantity'], $item['catalogReference']);

                continue;
            }
            foreach ($recipe->getLines() as $line) {
                $material = $line->getMaterial();
                if (!$material->isActive()) {
                    throw new ReservationRejected('MATERIAL_INACTIVE', 'A required material is inactive.');
                }

                $this->addDemand(
                    $demands,
                    $material,
                    Quantity::multiply($item['quantity'], $line->getQuantityPerUnit()),
                    $item['catalogReference'],
                );
            }
        }
        ksort($demands);

        return $demands;
    }

    /** @param array<string, array{material: Material, quantity: string, specifications: list<string>}> $demands */
    private function addDemand(array &$demands, Material $material, string $quantity, string $specificationUuid): void
    {
        $uuid = $material->getUuid();
        if (!isset($demands[$uuid])) {
            $demands[$uuid] = [
                'material' => $material,
                'quantity' => Quantity::ZERO,
                'specifications' => [],
            ];
        }

        $demands[$uuid]['quantity'] = Quantity::add($demands[$uuid]['quantity'], $quantity);
        $demands[$uuid]['specifications'][] = $specificationUuid;
        $demands[$uuid]['specifications'] = array_values(array_unique($demands[$uuid]['specifications']));
    }

    private function reject(
        InventoryReservation $reservation,
        string $code,
        string $reason,
        ?string $correlationId,
        ?string $causationId,
    ): InventoryReservation
    {
        $reservation->reject($code, $reason);
        $this->outbox->record('inventory.reservation.rejected.v1', 'inventory_reservation', $reservation->getReservationId(), [
            'reservationId' => $reservation->getReservationId(),
            'storeUuid' => $reservation->getStoreUuid(),
            'tradeOrderUuid' => $reservation->getTradeOrderUuid(),
            'storeOrderUuid' => $reservation->getStoreOrderUuid(),
            'reasonCode' => $code,
            'reason' => $reason,
            'rejectedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], $correlationId, $causationId);

        return $reservation;
    }

    private function assertStoreUuid(string $storeUuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $storeUuid) !== 1) {
            throw new \InvalidArgumentException('Store UUID is invalid.');
        }
    }
}

final class ReservationRejected extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
