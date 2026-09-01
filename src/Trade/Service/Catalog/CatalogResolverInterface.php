<?php

declare(strict_types=1);

namespace App\Trade\Service\Catalog;

/**
 * Trade-owned port for catalog reads. Store implements this without Trade importing Store entities.
 */
interface CatalogResolverInterface
{
    /**
     * Resolve a sellable specification for pricing, respecting Store visibility.
     * Returns null if not found, deleted, inactive, or not visible for the given storeCode.
     */
    public function resolveForPricing(int $specificationId, ?string $storeCode): ?CatalogItem;
}
