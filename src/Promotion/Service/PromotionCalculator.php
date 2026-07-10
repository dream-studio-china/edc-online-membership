<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Promotion\Entity\PromotionTemplate;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class PromotionCalculator implements PriceCalculatorInterface
{
    private const MAX_ITERATIONS = 20;

    public function __construct(
        private readonly PromotionServiceInterface $promotionService,
    ) {}

    public static function getPriority(): int
    {
        return 60;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        $appliedIds = [];

        $innerApplied = [];

        // Phase INNER: item-level promotions
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $promotion = $this->promotionService->getFirstAvailable(
                $context,
                PromotionTemplate::PHASE_INNER
            );

            if ($promotion === null) {
                break;
            }

            $this->promotionService->apply($promotion, $context);

            $innerApplied[] = [
                'promotionId' => $promotion->getId(),
                'promotionName' => $promotion->getName(),
                'templateName' => $promotion->getTemplate()?->getName(),
                'type' => $promotion->getTemplate()?->getType(),
                'config' => $promotion->getConfig(),
                'snapshot' => [
                    'totalAmount' => $context->totalAmount,
                    'itemsCount' => count($context->items),
                ],
                'iteration' => $i,
            ];

            if ($promotion->getConflictMode() === 'exclusive') {
                break;
            }

            if ($promotion->getConflictMode() === 'lock_item') {
                $appliedIds[] = $promotion->getId();
            }
        }

        // Phase OUTER: order-level promotions
        $outerPromotion = $this->promotionService->getFirstAvailable(
            $context,
            PromotionTemplate::PHASE_OUTER
        );

        $outerApplied = null;
        if ($outerPromotion !== null) {
            $this->promotionService->apply($outerPromotion, $context);

            $outerApplied = [
                'promotionId' => $outerPromotion->getId(),
                'promotionName' => $outerPromotion->getName(),
                'templateName' => $outerPromotion->getTemplate()?->getName(),
                'type' => $outerPromotion->getTemplate()?->getType(),
                'config' => $outerPromotion->getConfig(),
                'phase' => 'outer',
            ];
        }

        // Write to meta channel — Trade never sees this structure
        $result = ['inner' => $innerApplied];
        if ($outerApplied !== null) {
            $result['outer'] = $outerApplied;
        }
        $context->meta['promotion'] = $result;
    }
}
