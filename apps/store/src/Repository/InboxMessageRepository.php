<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\InboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxMessage> */
class InboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxMessage::class);
    }

    public function findOneByEventId(string $eventId): ?InboxMessage
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }
}
