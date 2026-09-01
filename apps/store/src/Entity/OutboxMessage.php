<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\OutboxMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OutboxMessageRepository::class)]
#[ORM\Table(name: 'store_outbox_message')]
#[ORM\UniqueConstraint(name: 'uniq_store_outbox_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_store_outbox_unpublished_available', columns: ['published_at', 'available_at'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', type: 'string', length: 36, unique: true)]
    private string $eventId;

    #[ORM\Column(name: 'correlation_id', type: 'string', length: 36, nullable: true)]
    private ?string $correlationId;

    #[ORM\Column(name: 'causation_id', type: 'string', length: 36, nullable: true)]
    private ?string $causationId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $topic;

    #[ORM\Column(name: 'aggregate_type', type: 'string', length: 80)]
    private string $aggregateType;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 64)]
    private string $aggregateId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    )
    {
        $this->eventId = UUID::v4();
        $this->correlationId = $correlationId ?? $this->eventId;
        $this->causationId = $causationId;
        $this->topic = $topic;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
        $this->availableAt = $this->occurredAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getEventId(): string { return $this->eventId; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getCausationId(): ?string { return $this->causationId; }
    public function getTopic(): string { return $this->topic; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getAvailableAt(): \DateTimeImmutable { return $this->availableAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastError(): ?string { return $this->lastError; }
    public function isPublished(): bool { return $this->publishedAt !== null; }
    public function markPublished(): self { $this->publishedAt = new \DateTimeImmutable(); return $this; }
    public function recordAttempt(?string $error, \DateTimeImmutable $availableAt): self { $this->attempts++; $this->lastError = $error; $this->availableAt = $availableAt; return $this; }
}
