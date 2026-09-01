<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Store\Repository\TradeOrderCancellationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeOrderCancellationRepository::class)]
#[ORM\Table(name: 'store_trade_order_cancellation')]
#[ORM\UniqueConstraint(name: 'uniq_store_trade_order_cancellation_trade_order', columns: ['trade_order_uuid'])]
class TradeOrderCancellation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'trade_order_uuid', type: 'string', length: 36, unique: true)]
    private string $tradeOrderUuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $cancelledAt;

    public function __construct(string $tradeOrderUuid, string $storeUuid, \DateTimeImmutable $cancelledAt)
    {
        $this->tradeOrderUuid = $tradeOrderUuid;
        $this->storeUuid = $storeUuid;
        $this->cancelledAt = $cancelledAt;
    }

    public function getTradeOrderUuid(): string { return $this->tradeOrderUuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getCancelledAt(): \DateTimeImmutable { return $this->cancelledAt; }
}
