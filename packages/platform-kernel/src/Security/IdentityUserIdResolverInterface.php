<?php

declare(strict_types=1);

namespace App\Core\Security;

interface IdentityUserIdResolverInterface
{
    public function resolveIdentityUserId(string $userUuid): ?int;
}
