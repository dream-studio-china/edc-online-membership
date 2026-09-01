<?php

declare(strict_types=1);

namespace App\Bridge\Identity;

use App\Core\Security\UserUuidResolverInterface;
use App\Identity\Main\Repository\UserRepository;

final readonly class IdentityUserUuidResolver implements UserUuidResolverInterface
{
    public function __construct(private UserRepository $userRepository) {}

    public function resolveUserUuid(int $userId): ?string
    {
        return $this->userRepository->find($userId)?->getUuid();
    }
}
