<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Trade\Entity\Product;

/** @extends BaseService<\App\Trade\Entity\Product> */
class ProductService extends BaseService implements ProductServiceInterface
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
    ) {
        parent::__construct($container, Product::class);
    }
}
