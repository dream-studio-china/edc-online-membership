<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;

interface PromotionStrategyInterface
{
    /** e.g. 'full_reduction', 'discount', 'gift' */
    public static function supportedType(): string;

    /**
     * Apply the promotion action to mutate the price calculation context.
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void;
}
