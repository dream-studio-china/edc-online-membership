<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Repository\PayerDirectoryRepository;

final readonly class PayerDirectoryReferenceResolver implements PayerReferenceResolverInterface
{
    public function __construct(private PayerDirectoryRepository $repository) {}

    public function resolve(string $reference): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $reference)) {
            return $reference;
        }
        if (!ctype_digit($reference)) {
            return null;
        }

        return $this->repository->findByIdentityUserId((int) $reference)?->getUserUuid();
    }
}
