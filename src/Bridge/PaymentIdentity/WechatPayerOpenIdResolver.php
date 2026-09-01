<?php

declare(strict_types=1);

namespace App\Bridge\PaymentIdentity;

use App\Identity\Wechat\Repository\WechatUserRepository;
use App\Payment\Bridge\Wechat\WechatPayerOpenIdResolverInterface;

final readonly class WechatPayerOpenIdResolver implements WechatPayerOpenIdResolverInterface
{
    public function __construct(private WechatUserRepository $wechatUserRepository) {}

    public function resolveOpenId(string $payerUuid): ?string
    {
        return $this->wechatUserRepository->findByUserUuid($payerUuid)?->getOpenid();
    }
}
