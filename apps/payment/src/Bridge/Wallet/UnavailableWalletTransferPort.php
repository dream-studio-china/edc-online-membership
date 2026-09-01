<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wallet;

use CrudPlatform\IntegrationContracts\Wallet\WalletTransferPortInterface;

final class UnavailableWalletTransferPort implements WalletTransferPortInterface
{
    public function debitOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string { throw new \RuntimeException('Wallet integration is not configured.'); }
    public function creditOwner(string $ownerUuid, string $currency, int $systemWalletId, int $amount, string $referenceId, string $description): string { throw new \RuntimeException('Wallet integration is not configured.'); }
}
