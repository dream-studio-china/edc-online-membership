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

    public function __construct(array $inputItems, string $currency = 'CNY')
    {
        $this->inputItems = $inputItems;
        $this->currency = $currency;
    }
}
