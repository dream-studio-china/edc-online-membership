<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\Specification;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class OrderService extends BaseService implements OrderServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('trade.price_calculator')]
        private readonly iterable $priceCalculators,
    ) {
        parent::__construct($container, Order::class);
    }

    public function calculatePrices(array $items, string $currency = 'CNY'): PriceCalculationResult
    {
        $context = new PriceCalculationContext($items, $currency);

        $sortedCalculators = $this->getSortedCalculators();
        foreach ($sortedCalculators as $calculator) {
            $calculator->calculate($context);
        }

        return PriceCalculationResult::fromContext($context);
    }

    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null): Order
    {
        return $this->wrapInTransaction(function () use ($calculatedItems, $user, $totalAmount, $currency, $notes) {
            $order = new Order();
            if ($user instanceof User) {
                $order->setUser($user);
            } elseif (is_array($user) && isset($user['id'])) {
                $order->setUser($this->getEntityManager()->getReference(User::class, $user['id']));
            }
            $order->setTotalAmount($totalAmount);
            $order->setCurrency($currency);
            $order->setNotes($notes);

            foreach ($calculatedItems as $item) {
                $orderItem = new OrderItem();
                if (isset($item['specification']) && $item['specification'] instanceof Specification) {
                    $orderItem->setSpecification($item['specification']);
                }
                $orderItem->setQuantity($item['quantity']);
                $orderItem->setUnitPrice($item['unitPrice']);
                $orderItem->setPrice($item['price']);

                if (isset($item['specSnapshot'])) {
                    $orderItem->setSpecSnapshot($item['specSnapshot']);
                }
                if (isset($item['productSnapshot'])) {
                    $orderItem->setProductSnapshot($item['productSnapshot']);
                }

                $order->addItem($orderItem);
            }

            $this->getEntityManager()->persist($order);
            $this->getEntityManager()->flush();

            return $order;
        });
    }

    private function getSortedCalculators(): array
    {
        $calculators = is_array($this->priceCalculators)
            ? $this->priceCalculators
            : iterator_to_array($this->priceCalculators);

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        return $calculators;
    }
}
