<?php

declare(strict_types=1);

namespace App\Settlement\Integration\Fixture;

use App\Settlement\Contract\RecipientReference;
use App\Settlement\Contract\SettlementContext;
use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Contract\SettlementSubject;
use App\Settlement\Context\SettlementContextResolverInterface;

/**
 * Test/demo context resolver. It builds a frozen context from the funding snapshot,
 * where `facts` holds flat scalar facts and `recipientCandidates` holds named
 * recipient references. Supports the `fixture.order.v1` subject.
 */
final class FundingSnapshotContextResolver implements SettlementContextResolverInterface
{
    public static function getName(): string
    {
        return 'funding_snapshot';
    }

    public function supports(SettlementSubject $subject): bool
    {
        return str_starts_with($subject->type, 'fixture.');
    }

    public function resolve(
        SettlementFunding $funding,
        SettlementSubject $subject,
        \DateTimeImmutable $asOf,
    ): SettlementContext {
        $facts = $funding->snapshot['facts'] ?? [];
        if (!is_array($facts)) {
            throw new \InvalidArgumentException('Funding snapshot facts must be an object');
        }
        $candidateSnapshot = $funding->snapshot['recipientCandidates'] ?? [];
        if (!is_array($candidateSnapshot)) {
            throw new \InvalidArgumentException('Funding snapshot recipientCandidates must be an object');
        }
        $candidates = [];
        foreach ($candidateSnapshot as $key => $ref) {
            if (is_string($key) && is_array($ref) && isset($ref['type'], $ref['id'])) {
                $candidates[$key] = new RecipientReference((string) $ref['type'], (string) $ref['id']);
            }
        }

        return new SettlementContext(
            subject: $subject,
            currency: $funding->currency,
            distributableAmountQuantum: $funding->amountQuantum,
            calculationScale: $funding->calculationScale,
            facts: $facts,
            recipientCandidates: $candidates,
            sourceSnapshotVersion: '1',
            resolvedAt: $asOf,
        );
    }
}
