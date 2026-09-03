<?php

declare(strict_types=1);

namespace App\Store\Service\Catalog;

use App\Store\Repository\SpecificationRepository;
use App\Store\Repository\StoreRepository;
use App\Trade\Service\Catalog\CatalogItem;
use App\Trade\Service\Catalog\CatalogResolverInterface;

final readonly class StoreCatalogResolver implements CatalogResolverInterface
{
    public function __construct(
        private SpecificationRepository $specificationRepository,
        private StoreRepository $storeRepository,
    ) {
    }

    public function resolveForPricing(int|string $specificationId, ?string $storeCode): ?CatalogItem
    {
        $specification = null;
        if (is_int($specificationId) || (is_string($specificationId) && ctype_digit($specificationId))) {
            $specification = $this->specificationRepository->find((int) $specificationId);
        }
        if ($specification === null && is_string($specificationId) && \App\Core\Utils\UUID::is_valid($specificationId)) {
            $specification = $this->specificationRepository->findOneBy(['uuid' => $specificationId]);
        }
        if ($specification === null && is_string($specificationId) && !ctype_digit($specificationId)) {
            // fallback: try as uuid string directly (already handled above) or as string id
            $specification = $this->specificationRepository->findOneBy(['uuid' => $specificationId]);
        }
        if ($specification === null || $specification->getIsDeleted() || !$specification->isActive()) {
            return null;
        }

        $product = $specification->getProduct();
        if ($product === null || $product->getIsDeleted() || !$product->isActive()) {
            return null;
        }

        // Store visibility
        $productStore = $product->getStore();
        if ($storeCode !== null && $storeCode !== '') {
            $store = $this->storeRepository->findOneByCode($storeCode);
            if ($productStore !== null) {
                if ($store === null || $productStore->getId() !== $store->getId()) {
                    return null;
                }
            }
        } elseif ($productStore !== null) {
            return null;
        }

        return new CatalogItem(
            id: $specification->getId() ?? 0,
            uuid: $specification->getUuid(),
            name: $specification->getName(),
            price: $specification->getPrice(),
            status: $specification->getStatus(),
            isDeleted: $specification->getIsDeleted(),
            productId: $product->getId() ?? 0,
            productUuid: $product->getUuid(),
            productName: $product->getName(),
            productIsDeleted: $product->getIsDeleted(),
            productStatus: $product->getStatus(),
            storeUuid: $productStore?->getUuid(),
            storeId: $productStore?->getId(),
        );
    }
}
