<?php

namespace App\Common\Service;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use App\Core\Service\BaseServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Core\Service\ServiceLocatorInterface;

class ContentService extends BaseService implements BaseServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        ServiceLocatorInterface $locator
    ) {
        parent::__construct($container, Content::class, $locator);
    }
}
