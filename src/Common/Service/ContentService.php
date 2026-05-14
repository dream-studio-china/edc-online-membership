<?php

namespace App\Common\Service;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentService extends BaseService implements ContentServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Content::class);
    }
}
