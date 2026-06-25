<?php

declare(strict_types=1);

namespace App\Payment\Entity;

use App\Core\Utils\UUID;
use App\Payment\Repository\DeductionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeductionRepository::class)]
#[ORM\Table(name: 'payment_deduction')]
#[ORM\UniqueConstraint(name: 'uniq_payment_deduction_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_deduction_reference', columns: ['reference_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_deduction_invoice_type', columns: ['invoice_id', 'type'])]
#[ORM\Index(name: 'idx_payment_deduction_invoice_status', columns: ['invoice_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Deduction
{
    public const TYPE_WALLET_BALANCE = 'wallet_balance';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(type: 'string', length: 30)]
    private string $type = self::TYPE_WALLET_BALANCE;

    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 10)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'wallet_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $walletTransactionId = null;

    #[ORM\Column(name: 'refund_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $refundTransactionId = null;

    #[ORM\Column(name: 'reference_id', type: 'string', length: 64, unique: true)]
    private string $referenceId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(name: 'released_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'refunded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    public function __construct(Invoice $invoice, int $amount, string $currency, string $referenceId)
    {
        $this->uuid = UUID::v4();
        $this->invoice = $invoice;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->referenceId = $referenceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getType(): string { return $this->type; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getWalletTransactionId(): ?string { return $this->walletTransactionId; }
    public function getRefundTransactionId(): ?string { return $this->refundTransactionId; }
    public function getReferenceId(): string { return $this->referenceId; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }

    public function markApplied(string $walletTransactionId, ?array $metadata = null): self
    {
        $this->status = self::STATUS_APPLIED;
        $this->walletTransactionId = $walletTransactionId;
        $this->appliedAt = new \DateTimeImmutable();
        $this->metadata = $metadata ?: $this->metadata;
        return $this;
    }

    public function markReleased(string $refundTransactionId, string $reason): self
    {
        $this->status = self::STATUS_RELEASED;
        $this->refundTransactionId = $refundTransactionId;
        $this->releasedAt = new \DateTimeImmutable();
        $this->appendMetadata('releaseReason', $reason);
        return $this;
    }

    public function markRefunded(string $refundTransactionId, string $reason): self
    {
        $this->status = self::STATUS_REFUNDED;
        $this->refundTransactionId = $refundTransactionId;
        $this->refundedAt = new \DateTimeImmutable();
        $this->appendMetadata('refundReason', $reason);
        return $this;
    }

    public function markFailed(string $reason): self
    {
        $this->status = self::STATUS_FAILED;
        $this->appendMetadata('failedReason', $reason);
        return $this;
    }

    private function appendMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
