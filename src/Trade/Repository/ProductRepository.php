<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Store\Entity\Product;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @deprecated Use App\Store\Repository\ProductRepository - kept for BC during Store catalog migration
 * @extends \App\Store\Repository\ProductRepository
 */
class ProductRepository extends \App\Store\Repository\ProductRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry);
    }
}
