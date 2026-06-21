<?php

declare(strict_types=1);

namespace App\Tests\Trade\Pricing;

use App\Trade\Entity\Product;
use App\Trade\Entity\Specification;
use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
use App\Trade\Service\SpecificationServiceInterface;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    public function testContextInitializesCorrectly(): void
    {
        $input = [['specificationId' => 1, 'quantity' => 2]];
        $context = new PriceCalculationContext($input, 'CNY');

        self::assertSame($input, $context->inputItems);
        self::assertSame('CNY', $context->currency);
        self::assertSame(0, $context->totalAmount);
        self::assertSame([], $context->items);
        self::assertSame([], $context->meta);
    }

    public function testContextDefaultCurrency(): void
    {
        $context = new PriceCalculationContext([]);
        self::assertSame('CNY', $context->currency);
    }

    public function testResultFromContext(): void
    {
        $context = new PriceCalculationContext([], 'EUR');
        $context->totalAmount = 5000;
        $context->items = [['specificationId' => 1, 'price' => 5000]];

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame(5000, $result->totalAmount);
        self::assertSame('EUR', $result->currency);
        self::assertSame($context->items, $result->items);
    }

    public function testResultConstructor(): void
    {
        $items = [['price' => 100]];
        $result = new PriceCalculationResult(100, 'CNY', $items);

        self::assertSame(100, $result->totalAmount);
        self::assertSame('CNY', $result->currency);
        self::assertSame($items, $result->items);
    }

    public function testBasePriceCalculatorPopulatesUnitPriceAndSnapshots(): void
    {
        $product = new Product();
        $product->setName('iPhone');
        $specification = new Specification();
        $specification->setProduct($product);
        $specification->setName('128GB');
        $specification->setPrice(100000);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 2],
            ['specificationId' => 2, 'quantity' => 1],
        ]);

        $calculator->calculate($context);

        self::assertCount(2, $context->items);

        self::assertSame(100000, $context->items[0]['unitPrice']);
        self::assertSame(2, $context->items[0]['quantity']);
        self::assertSame('128GB', $context->items[0]['specificationName']);
        self::assertSame('iPhone', $context->items[0]['productSnapshot']['name']);

        self::assertSame(100000, $context->items[1]['unitPrice']);
        self::assertSame(1, $context->items[1]['quantity']);
    }

    public function testBasePriceCalculatorThrowsWhenSpecNotFound(): void
    {
        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn(null);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 999, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenSpecDeleted(): void
    {
        $specification = new Specification();
        $specification->setIsDeleted(true);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenSpecInactive(): void
    {
        $specification = new Specification();
        $specification->setStatus(Specification::STATUS_INACTIVE);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenProductNotAvailable(): void
    {
        $product = new Product();
        $product->setIsDeleted(true);
        $specification = new Specification();
        $specification->setProduct($product);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorDefaultQuantityIsOne(): void
    {
        $product = new Product();
        $specification = new Specification();
        $specification->setProduct($product);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculator = new BasePriceCalculator($specService);
        $context = new PriceCalculationContext([
            ['specificationId' => 1],
        ]);

        $calculator->calculate($context);

        self::assertSame(1, $context->items[0]['quantity']);
    }

    public function testQuantityCalculatorComputesPriceCorrectly(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['unitPrice' => 100, 'quantity' => 3, 'price' => 0],
            ['unitPrice' => 50, 'quantity' => 2, 'price' => 0],
            ['unitPrice' => 200, 'quantity' => 1, 'price' => 0],
        ];

        $calculator = new QuantityCalculator();
        $calculator->calculate($context);

        self::assertSame(300, $context->items[0]['price']);
        self::assertSame(100, $context->items[1]['price']);
        self::assertSame(200, $context->items[2]['price']);
    }

    public function testTotalAggregatorComputesTotalAmount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['price' => 300],
            ['price' => 100],
            ['price' => 200],
        ];

        $calculator = new TotalAggregator();
        $calculator->calculate($context);

        self::assertSame(600, $context->totalAmount);
    }

    public function testTotalAggregatorWithEmptyItems(): void
    {
        $context = new PriceCalculationContext([]);
        $calculator = new TotalAggregator();
        $calculator->calculate($context);

        self::assertSame(0, $context->totalAmount);
    }

    public function testPipelineExecutionOrder(): void
    {
        $product = new Product();
        $specification = new Specification();
        $specification->setProduct($product);
        $specification->setPrice(1000);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($specification);

        $calculators = [
            new BasePriceCalculator($specService),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 5],
        ]);

        foreach ($calculators as $calculator) {
            $calculator->calculate($context);
        }

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame(5000, $result->totalAmount);
        self::assertCount(1, $result->items);
        self::assertSame(1000, $result->items[0]['unitPrice']);
        self::assertSame(5, $result->items[0]['quantity']);
        self::assertSame(5000, $result->items[0]['price']);
    }

    public function testPricingPriorityOrder(): void
    {
        self::assertLessThan(QuantityCalculator::getPriority(), BasePriceCalculator::getPriority());
        self::assertLessThan(TotalAggregator::getPriority(), QuantityCalculator::getPriority());
    }

    public function testContextMalleableState(): void
    {
        $context = new PriceCalculationContext([]);
        $context->meta['promotion_applied'] = true;
        $context->meta['discount'] = 500;

        self::assertTrue($context->meta['promotion_applied']);
        self::assertSame(500, $context->meta['discount']);
    }

    public function testPipelineHandlesMultipleItemsWithDifferentPrices(): void
    {
        $product = new Product();
        $product->setName('Phone');

        $spec1 = new Specification();
        $spec1->setProduct($product);
        $spec1->setName('Red');
        $spec1->setPrice(1000);

        $spec2 = new Specification();
        $spec2->setProduct($product);
        $spec2->setName('Blue');
        $spec2->setPrice(1100);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturnOnConsecutiveCalls($spec1, $spec2);

        $calculators = [
            new BasePriceCalculator($specService),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 2],
            ['specificationId' => 2, 'quantity' => 3],
        ]);

        foreach ($calculators as $calculator) {
            $calculator->calculate($context);
        }

        $result = PriceCalculationResult::fromContext($context);
        self::assertSame(5300, $result->totalAmount);
    }
}
