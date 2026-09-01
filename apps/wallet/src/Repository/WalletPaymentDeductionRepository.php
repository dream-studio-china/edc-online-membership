<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\WalletPaymentDeduction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\WalletPaymentDeduction>
 */
class WalletPaymentDeductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletPaymentDeduction::class);
    }

    public function findWalletBalanceByInvoiceId(string $invoiceId): ?WalletPaymentDeduction
    {
        return $this->findOneBy(['invoiceId' => $invoiceId, 'type' => WalletPaymentDeduction::TYPE_WALLET_BALANCE]);
    }

    public function findAppliedByInvoiceId(string $invoiceId): ?WalletPaymentDeduction
    {
        return $this->findOneBy([
            'invoiceId' => $invoiceId,
            'type' => WalletPaymentDeduction::TYPE_WALLET_BALANCE,
            'status' => WalletPaymentDeduction::STATUS_APPLIED,
        ]);
    }

    /** @return WalletPaymentDeduction[] */
    public function findAppliedDeductionsByInvoiceId(string $invoiceId): array
    {
        return $this->findBy(['invoiceId' => $invoiceId, 'status' => WalletPaymentDeduction::STATUS_APPLIED]);
    }
}
