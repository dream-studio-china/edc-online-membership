<?php

declare(strict_types=1);

namespace App\Payment\Bridge\Wechat;

final class UnavailableWechatPayerOpenIdResolver implements WechatPayerOpenIdResolverInterface
{
    public function resolveOpenId(string $payerUuid): ?string { return null; }
}
