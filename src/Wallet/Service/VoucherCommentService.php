<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use App\Wallet\Entity\VoucherComment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wallet\Entity\VoucherComment> */
class VoucherCommentService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, VoucherComment::class);
    }
}
