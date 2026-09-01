<?php

declare(strict_types=1);

namespace App\Tests\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\MemberDiscountStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class MemberDiscountStrategyTest extends TestCase
{
    private MemberDiscountStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new MemberDiscountStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('member_discount', MemberDiscountStrategy::supportedType());
    }

    public function testApplyWithMatchingLevel(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['identity'] = ['profileLevel' => 'gold'];

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => 'silver']);

        // 10% off: 50000 * (100-90) / 100 = 5000, new total = 45000
        self::assertSame(45000, $context->totalAmount);
    }

    public function testApplyWithDiamondLevel(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100000;
        $context->meta['identity'] = ['profileLevel' => 'diamond'];

        $action = new AstNode('action_member_discount', ['rate' => 80]);

        $this->strategy->apply($action, $context, ['min_level' => 'gold']);

        // 20% off: 100000 * 20 / 100 = 20000, new total = 80000
        self::assertSame(80000, $context->totalAmount);
    }

    public function testApplyWithLowerLevelThanMin(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['identity'] = ['profileLevel' => 'bronze'];

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => 'gold']);

        // User is bronze, min is gold — no discount
        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithoutUser(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => 'silver']);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithoutProfileLevel(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['identity'] = [];

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => 'silver']);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithInvalidIdentitySnapshot(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['identity'] = 'not-a-snapshot';

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => 'gold']);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithDefaultMinLevel(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['identity'] = ['profileLevel' => 'bronze'];

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        // Default min_level = 'bronze'
        $this->strategy->apply($action, $context, []);

        // 10% off: 50000 * 10 / 100 = 5000, new total = 45000
        self::assertSame(45000, $context->totalAmount);
    }
}
