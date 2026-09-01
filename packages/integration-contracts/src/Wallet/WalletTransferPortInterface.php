<?php

declare(strict_types=1);

namespace CrudPlatform\IntegrationContracts\Wallet;

interface WalletTransferPortInterface
{
    public function debitOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string;

    public function creditOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string;
}
