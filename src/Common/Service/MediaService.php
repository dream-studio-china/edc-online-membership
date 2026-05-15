<?php

namespace App\Common\Service;

use App\Common\Entity\Media;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MediaService extends BaseService implements MediaServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Media::class);
    }
}
