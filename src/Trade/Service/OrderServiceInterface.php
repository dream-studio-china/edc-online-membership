<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Service\Pricing\PriceCalculationResult;

interface OrderServiceInterface extends BaseServiceInterface
{
    public function calculatePrices(array $items, string $currency = 'CNY'): PriceCalculationResult;

    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null): Order;
}
