<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Wallet\Entity\WalletTransaction;

/** @extends BaseService<\App\Wallet\Entity\WalletTransaction> */
class TransactionService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WalletTransaction::class);
    }
}
