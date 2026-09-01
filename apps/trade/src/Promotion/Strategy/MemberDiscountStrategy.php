<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
class MemberDiscountStrategy implements PromotionStrategyInterface
{
    /** @var array<string, int> */
    private const LEVEL_RANK = [
        'bronze' => 0,
        'silver' => 1,
        'gold' => 2,
        'platinum' => 3,
        'diamond' => 4,
    ];

    public static function supportedType(): string
    {
        return 'member_discount';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $minLevel = $config['min_level'] ?? 'bronze';
        $rate = (float) ($action->data['rate'] ?? 100);

        $identity = $context->meta['identity'] ?? null;
        if (!is_array($identity) || !is_string($identity['profileLevel'] ?? null)) {
            return;
        }

        $userLevel = $identity['profileLevel'];
        $minRank = self::LEVEL_RANK[$minLevel] ?? 0;
        $userRank = self::LEVEL_RANK[$userLevel] ?? 0;

        if ($userRank < $minRank) {
            return;
        }

        $discount = (int) ($context->totalAmount * (100 - $rate) / 100);
        $context->totalAmount = max(0, $context->totalAmount - $discount);
    }
}
