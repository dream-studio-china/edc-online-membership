<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

use App\Trade\Entity\Specification;
use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\SpecificationServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class BasePriceCalculator implements PriceCalculatorInterface
{
    public function __construct(
        private readonly SpecificationServiceInterface $specificationService,
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
