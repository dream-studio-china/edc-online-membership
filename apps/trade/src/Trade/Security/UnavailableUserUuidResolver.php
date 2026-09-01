<?php

declare(strict_types=1);

namespace App\Trade\Security;

use App\Core\Security\UserUuidResolverInterface;

final class UnavailableUserUuidResolver implements UserUuidResolverInterface
{
    public function resolveUserUuid(int $userId): ?string
    {
        return null;
    }
}
