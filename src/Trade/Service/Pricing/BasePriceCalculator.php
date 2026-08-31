<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

use App\Store\Entity\Specification;
use App\Store\Repository\StoreRepository;
use App\Store\Service\SpecificationServiceInterface as StoreSpecificationServiceInterface;
use App\Trade\Exception\SpecificationNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class BasePriceCalculator implements PriceCalculatorInterface
{
    public function __construct(
        private readonly StoreSpecificationServiceInterface $specificationService,
        private readonly ?StoreRepository $storeRepository = null,
    ) {
    }

    public static function getPriority(): int
    {
        return -100;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        foreach ($context->inputItems as $inputItem) {
            $specificationId = $inputItem['specificationId'];
            $quantity = $inputItem['quantity'] ?? 1;

            /** @var Specification|null $specification */
            $specification = $this->specificationService->get(['id' => $specificationId]);

            if ($specification === null || $specification->getIsDeleted()) {
                throw new SpecificationNotFoundException(
                    sprintf('Specification #%d not found or deleted.', $specificationId)
                );
            }

            if (!$specification->isActive()) {
                throw new SpecificationNotFoundException(
                    sprintf('Specification #%d is not active.', $specificationId)
                );
            }

            $product = $specification->getProduct();
            if ($product === null || $product->getIsDeleted() || !$product->isActive()) {
                throw new SpecificationNotFoundException(
                    sprintf('Product for specification #%d is not available.', $specificationId)
                );
            }

            // Store visibility: global (store IS NULL) OR owned by resolved Store
            $storeCode = $context->storeCode;
            if ($storeCode !== null && $storeCode !== '' && $this->storeRepository !== null) {
                $store = $this->storeRepository->findOneByCode($storeCode);
                $productStore = $product->getStore();
                if ($productStore !== null) {
                    if ($store === null || $productStore->getId() !== $store->getId()) {
                        throw new SpecificationNotFoundException(
                            sprintf('Specification #%d is not available for store %s.', $specificationId, $storeCode)
                        );
                    }
                }
            } elseif ($product->getStore() !== null) {
                // No Store context but product is store-private
                throw new SpecificationNotFoundException(
                    sprintf('Specification #%d is not available without store context.', $specificationId)
                );
            }

            $unitPrice = $specification->getPrice();

            $context->items[] = [
                'specification' => $specification,
                'specificationId' => $specification->getId(),
                'specificationName' => $specification->getName(),
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'price' => 0,
                'specSnapshot' => [
                    'id' => $specification->getId(),
                    'uuid' => $specification->getUuid(),
                    'name' => $specification->getName(),
                    'productId' => $product->getId(),
                ],
                'productSnapshot' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                ],
            ];
        }
    }
}
