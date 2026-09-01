<?php

declare(strict_types=1);

namespace App\Payment\Entity;

use App\Payment\Repository\PayerDirectoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PayerDirectoryRepository::class)]
#[ORM\Table(name: 'payment_payer_directory')]
#[ORM\UniqueConstraint(name: 'uniq_payment_payer_directory_identity_user_id', columns: ['identity_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_payer_directory_user_uuid', columns: ['user_uuid'])]
class PayerDirectory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'identity_user_id', type: 'integer', nullable: true)]
    private ?int $identityUserId;

    #[ORM\Column(name: 'user_uuid', type: 'string', length: 36, unique: true)]
    private string $userUuid;

    public function __construct(?int $identityUserId, string $userUuid)
    {
        $this->identityUserId = $identityUserId;
        $this->userUuid = $userUuid;
    }

    public function getId(): ?int { return $this->id; }
    public function getIdentityUserId(): ?int { return $this->identityUserId; }
    public function setIdentityUserId(?int $identityUserId): self { $this->identityUserId = $identityUserId; return $this; }
    public function getUserUuid(): string { return $this->userUuid; }
}
