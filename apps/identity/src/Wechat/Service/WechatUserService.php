<?php

declare(strict_types=1);

namespace App\Identity\Wechat\Service;

use App\Core\Service\BaseService;
use App\Identity\Wechat\Entity\WechatUser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Identity\Wechat\Entity\WechatUser> */
class WechatUserService extends BaseService implements WechatUserServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatUser::class);
    }
}
