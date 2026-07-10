<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseService;
use App\Promotion\Entity\Promotion;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PromotionService extends BaseService implements PromotionServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Promotion::class);
    }

    public function getAvailable(
        PriceCalculationContext $context,
        ?int $phase = null
    ): array {
        $criteria = ['enabled' => true];

        if ($context->storeCode !== null) {
            $criteria['storeCode'] = $context->storeCode;
        }

        /** @var Promotion[] $promotions */
        $promotions = $this->rep->findBy($criteria);

        $now = new \DateTimeImmutable();

        $filtered = array_filter($promotions, function (Promotion $promotion) use ($context, $now, $phase) {
            if (!$promotion->getTemplate() || !$promotion->getTemplate()->isEnabled()) {
                return false;
            }

            if ($phase !== null && $promotion->getTemplate()->getPhase() !== $phase) {
                return false;
            }

            if ($promotion->getStartTime() && $promotion->getStartTime() > $now) {
                return false;
            }

            if ($promotion->getEndTime() && $promotion->getEndTime() < $now) {
                return false;
            }

            return true;
        });

        return array_values($filtered);
    }

    public function getFirstAvailable(
        PriceCalculationContext $context,
        ?int $phase = null
    ): ?Promotion {
        $available = $this->getAvailable($context, $phase);

        return $available[0] ?? null;
    }

    public function apply(
        Promotion $promotion,
        PriceCalculationContext $context
    ): void {
        // Strategy dispatch will be implemented in Phase 3
    }
}
