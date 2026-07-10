<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
class NthItemDiscountStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'nth_discount';
    }

    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $position = (int) ($action->data['position'] ?? 0);
        $rate = (float) ($action->data['rate'] ?? 100);

        // Discount the price of the Nth item in each matching item group
        foreach ($context->items as $index => &$item) {
            if (($index + 1) === $position) {
                $originalPrice = $item['unitPrice'];
                $discountedPrice = (int) ($originalPrice * $rate / 100);
                $item['unitPrice'] = $discountedPrice;
                $item['price'] = $discountedPrice * $item['quantity'];
            }
        }
        unset($item);
    }
}
