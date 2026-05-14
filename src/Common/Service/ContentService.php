<?php

namespace App\Common\Service;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Core\Service\ServiceLocatorInterface;

class ContentService extends BaseService
{
    public function __construct(
        ContainerInterface $container,
        ServiceLocatorInterface $locator
    ) {
        parent::__construct($container, Content::class, $locator);
    }
}
