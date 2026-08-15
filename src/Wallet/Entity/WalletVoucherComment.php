<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use App\Wallet\Repository\WalletVoucherCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only annotation on a boundary voucher. Admin/finance may add
 * explanatory notes; entries are immutable (no update/delete path) so the
 * audit trail cannot be rewritten.
 */
#[ORM\Entity(repositoryClass: WalletVoucherCommentRepository::class)]
#[ORM\Table(name: 'wallet_voucher_comment')]
#[ORM\Index(name: 'idx_wallet_voucher_comment_voucher', columns: ['voucher_id'])]
#[ORM\HasLifecycleCallbacks]
class WalletVoucherComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WalletVoucher::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'voucher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WalletVoucher $voucher;

    #[ORM\Column(type: 'string', length: 64)]
    private string $actor;

    #[ORM\Column(type: 'string', length: 1000)]
    private string $text;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(WalletVoucher $voucher, string $actor, string $text)
    {
        $this->voucher = $voucher;
        $this->actor = $actor;
        $this->text = $text;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function setActor(string $actor): self
    {
        $this->actor = $actor;
        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function getVoucher(): WalletVoucher
    {
        return $this->voucher;
    }

    public function setVoucher(WalletVoucher $voucher): self
    {
        $this->voucher = $voucher;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
