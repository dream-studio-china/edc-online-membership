<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts;

interface IntegrationMessage
{
    public const string TYPE = '';
    public const int VERSION = 1;
    public const string TOPIC = '';

    /** @var array<string, mixed> */
    public array $envelope { get; }
}
