<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

class PriceCalculationContext
{
    public array $inputItems = [];

    public array $items = [];

    public int $totalAmount = 0;

    public string $currency = 'CNY';

    public array $meta = [];

    /** User object for promotion condition evaluation (member level, etc.) */
    public ?object $user = null;

    /** Store identifier for multi-store promotion filtering */
    public ?string $storeCode = null;

    /** Promotions applied during calculation (for audit/display) */
    public array $appliedPromotions = [];

    public function __construct(array $inputItems, string $currency = 'CNY')
    {
        $this->inputItems = $inputItems;
        $this->currency = $currency;
    }
}
