<?php

declare(strict_types=1);

namespace App\Payment\Repository;

use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deduction::class);
    }

    public function findWalletBalanceByInvoice(Invoice $invoice): ?Deduction
    {
        return $this->findOneBy(['invoice' => $invoice, 'type' => Deduction::TYPE_WALLET_BALANCE]);
    }

    public function findAppliedByInvoice(Invoice $invoice): ?Deduction
    {
        return $this->findOneBy([
            'invoice' => $invoice,
            'type' => Deduction::TYPE_WALLET_BALANCE,
            'status' => Deduction::STATUS_APPLIED,
        ]);
    }

    /** @return Deduction[] */
    public function findAppliedDeductions(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice, 'status' => Deduction::STATUS_APPLIED]);
    }
}
