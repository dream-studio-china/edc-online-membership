<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Core\Service\BaseService;
use App\Inventory\Entity\InventoryStock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<InventoryStock> */
final class InventoryStockService extends BaseService implements InventoryStockServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, InventoryStock::class);
    }
}
