<?php

declare(strict_types=1);

namespace App\Bridge\IdentityPayment;

use App\Identity\Main\Repository\UserRepository;
use App\Payment\Service\PayerDirectoryService;
use App\Payment\Service\PayerReferenceResolverInterface;

final readonly class IdentityPayerDirectoryReferenceResolver implements PayerReferenceResolverInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private PayerDirectoryService $payerDirectoryService,
    ) {}

    public function resolve(string $reference): ?string
    {
        if (!ctype_digit($reference)) {
            return null;
        }

        $user = $this->userRepository->find((int) $reference);
        if ($user === null || $user->getId() === null) {
            return null;
        }

        $this->payerDirectoryService->upsert($user->getId(), $user->getUuid());

        return $user->getUuid();
    }
}
