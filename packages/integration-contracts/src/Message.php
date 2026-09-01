<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts;

abstract readonly class Message implements IntegrationMessage
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope)
    {
    }
}
