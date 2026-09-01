<?php

declare(strict_types=1);

namespace App\Trade\Entity;

use App\Trade\Repository\TradeStoreDirectoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeStoreDirectoryRepository::class)]
#[ORM\Table(name: 'trade_store_directory')]
#[ORM\UniqueConstraint(name: 'uniq_trade_store_directory_uuid', columns: ['store_uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_trade_store_directory_code', columns: ['code'])]
class TradeStoreDirectory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, unique: true)]
    private string $storeUuid;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status;

    #[ORM\Column(name: 'source_updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $sourceUpdatedAt;

    public function __construct(string $storeUuid, string $code, string $name, string $status, \DateTimeImmutable $sourceUpdatedAt)
    {
        $this->storeUuid = $storeUuid;
        $this->code = $code;
        $this->name = $name;
        $this->status = $status;
        $this->sourceUpdatedAt = $sourceUpdatedAt;
    }

    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getStatus(): string { return $this->status; }
    public function isActive(): bool { return $this->status === 'active'; }

    public function upsert(string $code, string $name, string $status, \DateTimeImmutable $sourceUpdatedAt): void
    {
        if ($sourceUpdatedAt < $this->sourceUpdatedAt) {
            return;
        }

        $this->code = $code;
        $this->name = $name;
        $this->status = $status;
        $this->sourceUpdatedAt = $sourceUpdatedAt;
    }
}
