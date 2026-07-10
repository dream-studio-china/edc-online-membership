<?php

declare(strict_types=1);

namespace App\Tests\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\NthItemDiscountStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class NthItemDiscountStrategyTest extends TestCase
{
    private NthItemDiscountStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new NthItemDiscountStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('nth_discount', NthItemDiscountStrategy::supportedType());
    }

    public function testApplyDiscountToFirstItem(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
            ['sku' => 'B', 'unitPrice' => 20000, 'quantity' => 2, 'price' => 40000],
        ];

        $action = new AstNode('action_discount', ['position' => 1, 'rate' => 50]);

        $this->strategy->apply($action, $context, []);

        // First item: 10000 * 50 / 100 = 5000
        self::assertSame(5000, $context->items[0]['unitPrice']);
        self::assertSame(5000, $context->items[0]['price']);
        // Second item unchanged
        self::assertSame(20000, $context->items[1]['unitPrice']);
    }

    public function testApplyDiscountToSecondItem(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
            ['sku' => 'B', 'unitPrice' => 30000, 'quantity' => 1, 'price' => 30000],
        ];

        $action = new AstNode('action_discount', ['position' => 2, 'rate' => 60]);

        $this->strategy->apply($action, $context, []);

        // First item unchanged
        self::assertSame(10000, $context->items[0]['unitPrice']);
        // Second item: 30000 * 60 / 100 = 18000
        self::assertSame(18000, $context->items[1]['unitPrice']);
        self::assertSame(18000, $context->items[1]['price']);
    }

    public function testApplyDiscountToThirdItem(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
            ['sku' => 'B', 'unitPrice' => 20000, 'quantity' => 1, 'price' => 20000],
            ['sku' => 'C', 'unitPrice' => 50000, 'quantity' => 1, 'price' => 50000],
        ];

        $action = new AstNode('action_discount', ['position' => 3, 'rate' => 0]);

        $this->strategy->apply($action, $context, []);

        // First two unchanged
        self::assertSame(10000, $context->items[0]['unitPrice']);
        self::assertSame(20000, $context->items[1]['unitPrice']);
        // Third item: 50000 * 0 / 100 = 0 (free)
        self::assertSame(0, $context->items[2]['unitPrice']);
        self::assertSame(0, $context->items[2]['price']);
    }

    public function testNoDiscountWhenPositionOutOfRange(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
        ];

        $action = new AstNode('action_discount', ['position' => 5, 'rate' => 50]);

        $this->strategy->apply($action, $context, []);

        self::assertSame(10000, $context->items[0]['unitPrice']);
    }

    public function testDefaultPositionAndRate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
        ];

        $action = new AstNode('action_discount', []);

        $this->strategy->apply($action, $context, []);

        // position defaults to 0 (no match), rate defaults to 100 (no change)
        self::assertSame(10000, $context->items[0]['unitPrice']);
    }

    public function testRate100LeavesPriceUnchanged(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 1, 'price' => 10000],
        ];

        $action = new AstNode('action_discount', ['position' => 1, 'rate' => 100]);

        $this->strategy->apply($action, $context, []);

        // 100% of original = same price
        self::assertSame(10000, $context->items[0]['unitPrice']);
    }

    public function testPriceUpdatedWithQuantityMultiplier(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 5000, 'quantity' => 3, 'price' => 15000],
        ];

        $action = new AstNode('action_discount', ['position' => 1, 'rate' => 40]);

        $this->strategy->apply($action, $context, []);

        // unitPrice: 5000 * 40 / 100 = 2000
        // price: 2000 * 3 = 6000
        self::assertSame(2000, $context->items[0]['unitPrice']);
        self::assertSame(6000, $context->items[0]['price']);
    }

    public function testOnlyNthItemDiscountedWithMultipleItems(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['sku' => 'A', 'unitPrice' => 10000, 'quantity' => 2, 'price' => 20000],
            ['sku' => 'B', 'unitPrice' => 20000, 'quantity' => 3, 'price' => 60000],
            ['sku' => 'C', 'unitPrice' => 30000, 'quantity' => 1, 'price' => 30000],
            ['sku' => 'D', 'unitPrice' => 40000, 'quantity' => 1, 'price' => 40000],
        ];

        $action = new AstNode('action_discount', ['position' => 2, 'rate' => 25]);

        $this->strategy->apply($action, $context, []);

        // Only the 2nd item (index 1) should be discounted
        self::assertSame(10000, $context->items[0]['unitPrice']);
        // 20000 * 25 / 100 = 5000
        self::assertSame(5000, $context->items[1]['unitPrice']);
        self::assertSame(15000, $context->items[1]['price']);
        self::assertSame(30000, $context->items[2]['unitPrice']);
        self::assertSame(40000, $context->items[3]['unitPrice']);
    }
}
