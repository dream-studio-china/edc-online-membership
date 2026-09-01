<?php

declare(strict_types=1);

namespace App\Bridge\Identity;

use App\Core\Security\IdentityUserIdResolverInterface;
use App\Identity\Main\Repository\UserRepository;

final readonly class IdentityUserIdResolver implements IdentityUserIdResolverInterface
{
    public function __construct(private UserRepository $userRepository) {}

    public function resolveIdentityUserId(string $userUuid): ?int
    {
        return $this->userRepository->findByUuid($userUuid)?->getId();
    }
}
