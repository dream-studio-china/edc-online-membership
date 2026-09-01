<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\TradeOrderCancellation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TradeOrderCancellation> */
final class TradeOrderCancellationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeOrderCancellation::class);
    }

    public function findOneByTradeOrderUuid(string $tradeOrderUuid): ?TradeOrderCancellation
    {
        return $this->findOneBy(['tradeOrderUuid' => $tradeOrderUuid]);
    }
}
