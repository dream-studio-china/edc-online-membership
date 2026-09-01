<?php

declare(strict_types=1);

namespace App\Payment\Service;

interface PayerReferenceResolverInterface
{
    /** Resolves a legacy numeric identity ID or a stable payer UUID. */
    public function resolve(string $reference): ?string;
}
