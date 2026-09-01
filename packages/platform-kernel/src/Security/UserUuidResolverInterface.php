<?php

declare(strict_types=1);

namespace App\Core\Security;

interface UserUuidResolverInterface
{
    public function resolveUserUuid(int $userId): ?string;
}
