<?php

declare(strict_types=1);

namespace App\Core\Security;

interface IdentityProfilePrincipalInterface
{
    public function getId(): ?int;

    public function getProfileLevel(): ?string;
}
