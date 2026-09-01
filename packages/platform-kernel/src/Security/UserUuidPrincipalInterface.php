<?php

declare(strict_types=1);

namespace App\Core\Security;

interface UserUuidPrincipalInterface
{
    public function getUuid(): string;
}
