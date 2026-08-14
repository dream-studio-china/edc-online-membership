<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\WalletVoucher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\WalletVoucher>
 */
class WalletVoucherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletVoucher::class);
    }

    public function findByUuid(string $uuid): ?WalletVoucher
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByReferenceId(string $referenceId): ?WalletVoucher
    {
        return $this->findOneBy(['referenceId' => $referenceId]);
    }

    public function findByVoucherSource(string $voucherType, string $voucherId): ?WalletVoucher
    {
        return $this->findOneBy(['voucherType' => $voucherType, 'voucherId' => $voucherId]);
    }

    /**
     * @return WalletVoucher[]
     */
    public function findAppliedByWallet(int $walletId, int $limit = 50): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.wallet = :walletId')
            ->andWhere('v.status = :status')
            ->setParameter('walletId', $walletId)
            ->setParameter('status', WalletVoucher::STATUS_APPLIED)
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
