<?php

declare(strict_types=1);

namespace App\Common\Security;

use App\Core\Security\UserUuidResolverInterface;

final class NullUserUuidResolver implements UserUuidResolverInterface
{
    public function resolveUserUuid(int $userId): ?string
    {
        return null;
    }
}
