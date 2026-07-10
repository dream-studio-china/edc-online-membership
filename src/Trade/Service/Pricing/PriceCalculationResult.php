<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

class PriceCalculationResult
{
    public int $totalAmount;
    public string $currency;
    public array $items;
    public array $meta;

    public function __construct(int $totalAmount, string $currency, array $items, array $meta = [])
    {
        $this->totalAmount = $totalAmount;
        $this->currency = $currency;
        $this->items = $items;
        $this->meta = $meta;
    }

    public static function fromContext(PriceCalculationContext $context): self
    {
        return new self(
            $context->totalAmount,
            $context->currency,
            $context->items,
            $context->meta,
        );
    }
}
