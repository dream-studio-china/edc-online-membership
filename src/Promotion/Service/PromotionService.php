<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseService;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Strategy\PromotionStrategyInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PromotionService extends BaseService implements PromotionServiceInterface
{
    /** @param iterable<PromotionStrategyInterface> $strategies */
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('promotion.strategy')]
        private readonly iterable $strategies = [],
    ) {
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

        $evaluator = $this->createEvaluator();
        $now = new \DateTimeImmutable();

        $filtered = array_filter($promotions, function (Promotion $promotion) use ($context, $evaluator, $now, $phase) {
            $template = $promotion->getTemplate();
            if (!$template || !$template->isEnabled()) {
                return false;
            }

            if ($phase !== null && $template->getPhase() !== $phase) {
                return false;
            }

            if ($promotion->getStartTime() && $promotion->getStartTime() > $now) {
                return false;
            }

            if ($promotion->getEndTime() && $promotion->getEndTime() < $now) {
                return false;
            }

            return $this->evaluateDslConditions($template, $evaluator, $context, $promotion->getConfig() ?? []);
        });

        // Sort by priority from DSL AST
        $sorted = array_values($filtered);
        usort($sorted, function (Promotion $a, Promotion $b) {
            return $this->getPriority($b) <=> $this->getPriority($a);
        });

        return $sorted;
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
        $template = $promotion->getTemplate();
        if (!$template) {
            return;
        }

        $ast = $template->getAstCache();
        if (!$ast) {
            return;
        }

        $config = $promotion->getConfig() ?? [];
        $evaluator = $this->createEvaluator();

        // Find the 'do' actions
        $actions = $this->findDoActions($ast);

        if (!empty($actions)) {
            $evaluator->executeActions($actions, $template->getType(), $context, $config);
        }
    }

    private function evaluateDslConditions(
        PromotionTemplate $template,
        Evaluator $evaluator,
        PriceCalculationContext $context,
        array $config
    ): bool {
        $ast = $template->getAstCache();
        if (!$ast) {
            return true; // No DSL = always match
        }

        $whenNode = $this->findWhenNode($ast);
        if (!$whenNode) {
            return true;
        }

        foreach ($whenNode['children'] ?? [] as $condition) {
            $node = $this->arrayToAstNode($condition);
            if (!$evaluator->evaluateCondition($node, $context, $config)) {
                return false;
            }
        }

        return true;
    }

    private function findWhenNode(array $ast): ?array
    {
        foreach ($ast['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'when') {
                return $child;
            }
        }
        return null;
    }

    private function findDoActions(array $ast): array
    {
        $actions = [];
        foreach ($ast['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'do') {
                foreach ($child['children'] ?? [] as $action) {
                    $actions[] = $this->arrayToAstNode($action);
                }
            }
        }
        return $actions;
    }

    private function arrayToAstNode(array $data): \App\Promotion\Service\Dsl\AstNode
    {
        $children = [];
        foreach ($data['children'] ?? [] as $child) {
            $children[] = $this->arrayToAstNode($child);
        }
        return new \App\Promotion\Service\Dsl\AstNode(
            $data['type'] ?? 'unknown',
            $data['data'] ?? [],
            $children
        );
    }

    private function getPriority(Promotion $promotion): float
    {
        $ast = $promotion->getTemplate()?->getAstCache();
        if (!$ast) {
            return 0.0;
        }

        $priority = $ast['data']['priority'] ?? null;
        if (!$priority) {
            return 0.0;
        }

        $value = $priority['value'] ?? 0;
        if (is_numeric($value)) {
            return (float) $value;
        }

        // config.xxx reference — resolve from promotion config
        if (is_string($value) && str_starts_with($value, 'config.')) {
            $key = substr($value, 7);
            $config = $promotion->getConfig() ?? [];
            return (float) ($config[$key] ?? 0);
        }

        return 0.0;
    }

    private function createEvaluator(): Evaluator
    {
        $strategies = is_array($this->strategies)
            ? $this->strategies
            : iterator_to_array($this->strategies);

        return new Evaluator($strategies);
    }
}
