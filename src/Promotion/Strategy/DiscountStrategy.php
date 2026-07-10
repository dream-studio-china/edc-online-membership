<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
class DiscountStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'discount';
    }

    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $rate = $this->resolveValue($action->data['rate'] ?? 100, $config);
        $cap = isset($action->data['maxCap']) ? (int) ($action->data['maxCap'] * 100) : null;

        $discount = (int) ($context->totalAmount * (100 - $rate) / 100);

        if ($cap !== null && $discount > $cap) {
            $discount = $cap;
        }

        $context->totalAmount = max(0, $context->totalAmount - $discount);
    }

    private function resolveValue(mixed $value, array $config): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && str_starts_with($value, 'config.')) {
            $key = substr($value, 7);
            return (float) ($config[$key] ?? 0);
        }
        return 0.0;
    }
}
