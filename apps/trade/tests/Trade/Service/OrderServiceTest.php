<?php

declare(strict_types=1);

namespace App\Tests\Trade\Service;

use App\Identity\Main\Entity\User;
use App\Identity\Main\Entity\Profile;
use App\Trade\Entity\Order;
use App\Trade\Entity\Product;
use App\Trade\Entity\Specification;
use App\Trade\Service\OrderService;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
use App\Trade\Service\SpecificationServiceInterface;
use CrudPlatform\IntegrationContracts\Wallet\WalletTransferPortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private function createService(
        array $calculators,
        ?WalletTransferPortInterface $walletTransferPort = null,
    ): OrderService
    {
        $reflection = new \ReflectionClass(OrderService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('priceCalculators');
        $prop->setValue($service, $calculators);

        $walletTransferPortProp = $reflection->getProperty('walletTransferPort');
        $walletTransferPortProp->setValue($service, $walletTransferPort);

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

    public function testCalculatePricesAddsAuthenticatedIdentitySnapshot(): void
    {
        $user = new User();
        $user->setProfile(new Profile($user, Profile::LEVEL_GOLD));
        $service = $this->createService([new class implements PriceCalculatorInterface {
            public function calculate(PriceCalculationContext $context): void
            {
            }

            public static function getPriority(): int
            {
                return 0;
            }
        }]);
        (new \ReflectionProperty(\App\Core\Service\BaseService::class, 'user'))->setValue($service, $user);

        $result = $service->calculatePrices([]);

        self::assertSame([
            'id' => null,
            'profileLevel' => Profile::LEVEL_GOLD,
        ], $result->meta['identity']);
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

    public function testPayRejectsNonConfirmedOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_DRAFT);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "confirmed" status to pay');

        $service->pay($order, 9);
    }

    public function testPayRequiresWalletModule(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet module is not configured');

        $service->pay($order, 9);
    }

    public function testPayRequiresOrderUser(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService(
            [],
            $this->createMock(WalletTransferPortInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order has no associated user');

        $service->pay($order, 9);
    }

    public function testPayRequiresUserWallet(): void
    {
        $user = $this->createUser(42);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setCurrency('CNY');

        $walletTransferPort = $this->createMock(WalletTransferPortInterface::class);
        $walletTransferPort->expects(self::once())
            ->method('debitOwner')
            ->willThrowException(new \RuntimeException('No CNY wallet found for owner.'));

        $service = $this->createService([], $walletTransferPort);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No CNY wallet found for owner.');

        $service->pay($order, 9);
    }

    public function testPayTransfersFromUserWalletAndMarksPayment(): void
    {
        $user = $this->createUser(42);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setTotalAmount(1234)
            ->setCurrency('CNY');

        $walletTransferPort = $this->createMock(WalletTransferPortInterface::class);
        $walletTransferPort->expects(self::once())
            ->method('debitOwner')
            ->with($user->getUuid(), 'CNY', 9, 1234, 'manual-pay-ref', 'Payment for order #0')
            ->willReturn('transaction-1');

        $service = $this->createService([], $walletTransferPort);

        $service->pay($order, 9, 'wallet', 'manual-pay-ref');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getPaidAt());
        self::assertSame('wallet', $order->getPaymentMethod());
    }

    public function testRefundRejectsNonCompletedOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "completed" status to refund');

        $service->refund($order, 9, 'duplicate');
    }

    public function testRefundTransfersToUserWalletAndMarksRefund(): void
    {
        $user = $this->createUser(42);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_COMPLETED)
            ->setTotalAmount(1234)
            ->setCurrency('CNY');

        $walletTransferPort = $this->createMock(WalletTransferPortInterface::class);
        $walletTransferPort->expects(self::once())
            ->method('creditOwner')
            ->with($user->getUuid(), 'CNY', 9, 1234, 'manual-refund-ref', 'Refund for order #0: duplicate')
            ->willReturn('transaction-1');

        $service = $this->createService([], $walletTransferPort);

        $service->refund($order, 9, 'duplicate', 'manual-refund-ref');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getRefundedAt());
        self::assertSame('duplicate', $order->getRefundReason());
    }

    public function testFulfillRejectsNonPaidOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "paid" status to fulfill');

        $service->fulfill($order, []);
    }

    public function testFulfillStoresShippingData(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID);
        $service = $this->createService([]);

        $service->fulfill($order, [
            'trackingNumber' => 'TRACK-1',
            'shippingAddress' => 'Shanghai',
        ]);

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getFulfilledAt());
        self::assertSame('TRACK-1', $order->getTrackingNumber());
        self::assertSame('Shanghai', $order->getShippingAddress());
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $user->setUsername('user' . $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setPassword('hashed');

        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, $id);

        return $user;
    }

}
