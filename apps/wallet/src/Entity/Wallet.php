<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Wallet\Repository\WalletRepository::class)]
#[ORM\Table(name: 'wallet')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_wallet_owner_uuid_currency', columns: ['owner_uuid', 'currency'])]
class Wallet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'owner_uuid', type: 'string', length: 36)]
    private string $ownerUuid;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $balance = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $ownerUuid, string $currency = 'USD')
    {
        $this->ownerUuid = $ownerUuid;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('#%d %s %.2f %s', $this->id ?? 0, $this->ownerUuid, $this->balance / 100, $this->currency);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerUuid(): string
    {
        return $this->ownerUuid;
    }

    public function setOwnerUuid(string $ownerUuid): self { $this->ownerUuid = $ownerUuid; return $this; }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getBalanceAsFloat(): float
    {
        return $this->balance / 100;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        $this->touch();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
