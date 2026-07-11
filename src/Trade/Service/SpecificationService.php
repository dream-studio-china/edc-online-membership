<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Trade\Entity\Specification;

/** @extends BaseService<\App\Trade\Entity\Specification> */
class SpecificationService extends BaseService implements SpecificationServiceInterface
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
    ) {
        parent::__construct($container, Specification::class);
    }
}
