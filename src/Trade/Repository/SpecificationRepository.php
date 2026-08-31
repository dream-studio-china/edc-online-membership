<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Store\Entity\Specification;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @deprecated Use App\Store\Repository\SpecificationRepository - kept for BC during Store catalog migration
 * @extends \App\Store\Repository\SpecificationRepository
 */
class SpecificationRepository extends \App\Store\Repository\SpecificationRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry);
    }
}
