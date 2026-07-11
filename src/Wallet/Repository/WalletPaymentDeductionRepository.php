<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Payment\Entity\Invoice;
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

    public function findWalletBalanceByInvoice(Invoice $invoice): ?WalletPaymentDeduction
    {
        return $this->findOneBy(['invoiceId' => $invoice->getUuid(), 'type' => WalletPaymentDeduction::TYPE_WALLET_BALANCE]);
    }

    public function findAppliedByInvoice(Invoice $invoice): ?WalletPaymentDeduction
    {
        return $this->findOneBy([
            'invoiceId' => $invoice->getUuid(),
            'type' => WalletPaymentDeduction::TYPE_WALLET_BALANCE,
            'status' => WalletPaymentDeduction::STATUS_APPLIED,
        ]);
    }

    /** @return WalletPaymentDeduction[] */
    public function findAppliedDeductions(Invoice $invoice): array
    {
        return $this->findBy(['invoiceId' => $invoice->getUuid(), 'status' => WalletPaymentDeduction::STATUS_APPLIED]);
    }
}
