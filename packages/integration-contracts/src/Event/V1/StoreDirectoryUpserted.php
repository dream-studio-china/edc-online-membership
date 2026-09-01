<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Event\V1;

use CrudPlatform\IntegrationContracts\IntegrationMessage;

final readonly class StoreDirectoryUpserted implements IntegrationMessage
{
    public const string TYPE = 'store.directory.upserted';
    public const int VERSION = 1;
    public const string TOPIC = 'store.directory.upserted.v1';

    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope)
    {
    }
}
