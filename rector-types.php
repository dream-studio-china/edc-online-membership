<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Bundle230\Rector\Class_\AddAnnotationToRepositoryRector;
use Rector\Doctrine\TypedCollections\Rector\Class_\CompleteReturnDocblockFromToManyRector;

return RectorConfig::configure()
    ->withPaths([
    __DIR__ . '/apps/store/src',
    __DIR__ . '/apps/inventory/src',
    __DIR__ . '/apps/payment/src',
    __DIR__ . '/apps/wallet/src',
        __DIR__ . '/src',
        __DIR__ . '/packages/legacy-messenger-compat/src',
        __DIR__ . '/packages/platform-kernel/src',
    ])
    ->withRules([
        AddAnnotationToRepositoryRector::class,
        CompleteReturnDocblockFromToManyRector::class,
    ])
    ->withCache(__DIR__ . '/var/cache/rector-types');
