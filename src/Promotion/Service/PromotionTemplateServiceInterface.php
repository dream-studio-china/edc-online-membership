<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseServiceInterface;
use App\Promotion\Entity\PromotionTemplate;

interface PromotionTemplateServiceInterface extends BaseServiceInterface
{
    /**
     * Parse DSL text and return AST. Throws DslSyntaxException on failure.
     * @return array{ast: array, errors: array}
     */
    public function parseDsl(string $dsl): array;

    /**
     * Simulate promotion application against a sample context.
     */
    public function simulate(PromotionTemplate $template, array $sampleContext): array;
}
