<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

/**
 * A value-object proposal produced by a rule before plan persistence. It is not
 * itself a settlement allocation; the calculator converts proposals to allocations
 * and applies plan-level conservation and rounding.
 */
final readonly class AllocationProposal
{
    /**
     * @param array<string, mixed> $recipientSnapshot
     * @param array<string, mixed> $calculationEvidence
     */
    public function __construct(
        public string $allocationKey,
        public RecipientReference $recipient,
        public string $exactAmountQuantum,
        public int $calculationScale,
        public string $currency,
        public string $ruleCode,
        public ?string $ruleVersionUuid,
        public string $reasonCode,
        public array $recipientSnapshot = [],
        public array $calculationEvidence = [],
    ) {
    }
}
