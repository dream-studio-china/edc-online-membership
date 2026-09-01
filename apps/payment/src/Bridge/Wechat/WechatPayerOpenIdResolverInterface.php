<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wechat;

interface WechatPayerOpenIdResolverInterface
{
    public function resolveOpenId(string $payerUuid): ?string;
}
