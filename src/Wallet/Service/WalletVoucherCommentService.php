<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use App\Wallet\Entity\WalletVoucherComment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wallet\Entity\WalletVoucherComment> */
class WalletVoucherCommentService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WalletVoucherComment::class);
    }
}
