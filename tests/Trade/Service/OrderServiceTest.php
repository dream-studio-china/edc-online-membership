<?php

declare(strict_types=1);

namespace App\Tests\Trade\Service;

use App\Trade\Entity\Product;
use App\Trade\Entity\Specification;
use App\Trade\Service\OrderService;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
use App\Trade\Service\SpecificationServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private function createService(array $calculators): OrderService
    {
        $reflection = new \ReflectionClass(OrderService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('priceCalculators');
        $prop->setValue($service, $calculators);

        return $service;
    }

    public function testCalculatePricesDelegatesToPipeline(): void
    {
        $product = new Product();
        $product->setName('Phone');
        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setName('Red');
        $spec->setPrice(500);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($spec);

        $calculators = [
            new BasePriceCalculator($specService),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => 3],
        ]);

        self::assertInstanceOf(PriceCalculationResult::class, $result);
        self::assertSame(1500, $result->totalAmount);
        self::assertCount(1, $result->items);
        self::assertSame(500, $result->items[0]['unitPrice']);
        self::assertSame(3, $result->items[0]['quantity']);
        self::assertSame(1500, $result->items[0]['price']);
    }

    public function testCalculatePricesWithCustomCurrency(): void
    {
        $product = new Product();
        $spec = new Specification();
        $spec->setProduct($product);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($spec);

        $calculators = [
            new BasePriceCalculator($specService),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => 1],
        ], 'CNY');

        self::assertSame('CNY', $result->currency);
    }

    public function testCalculatePricesWithEmptyItems(): void
    {
        $service = $this->createService([]);

        $result = $service->calculatePrices([]);

        self::assertInstanceOf(PriceCalculationResult::class, $result);
        self::assertSame(0, $result->totalAmount);
        self::assertSame([], $result->items);
    }

    #[DataProvider('pricingCalculationsProvider')]
    public function testPricingCalculations(int $unitPrice, int $quantity, int $expectedTotal): void
    {
        $product = new Product();
        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setPrice($unitPrice);

        $specService = $this->createMock(SpecificationServiceInterface::class);
        $specService->method('get')->willReturn($spec);

        $calculators = [
            new BasePriceCalculator($specService),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => $quantity],
        ]);

        self::assertSame($expectedTotal, $result->totalAmount);
    }

    public static function pricingCalculationsProvider(): array
    {
        return [
            'zero price' => [0, 10, 0],
            'single item' => [100, 1, 100],
            'multiple items' => [500, 3, 1500],
            'large quantity' => [100, 100, 10000],
            'large price' => [999999, 1, 999999],
        ];
    }
}
