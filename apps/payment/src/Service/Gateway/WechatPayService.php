<?php

declare(strict_types=1);

namespace App\Payment\Service\Gateway;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\Pay\Application as Pay;

final class WechatPayService
{
    private ?MiniApp $miniApp = null;
    private ?Pay $payApp = null;

    public function __construct(
        private readonly string $miniappAppId,
        private readonly string $miniappSecret,
        private readonly string $payMchId,
        private readonly string $paySecretKey,
        private readonly string $payPrivateKeyPath,
        private readonly string $payCertificatePath,
        private readonly string $payPlatformCertPath = '',
        private readonly string $payPubKeyId = '',
        private readonly string $payPubKeyPath = '',
    ) {}

    public function getMiniApp(): MiniApp
    {
        return $this->miniApp ??= new MiniApp([
            'app_id' => $this->miniappAppId,
            'secret' => $this->miniappSecret,
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
    }

    public function getPayApp(): Pay
    {
        if ($this->payApp === null) {
            $config = [
                'mch_id' => (int) $this->payMchId,
                'secret_key' => $this->paySecretKey,
                'private_key' => $this->payPrivateKeyPath,
                'certificate' => $this->payCertificatePath,
                'http' => ['throw' => true, 'timeout' => 5.0],
            ];
            if ($this->payPlatformCertPath !== '') {
                $config['platform_certs'] = [$this->payPlatformCertPath];
            }
            if ($this->payPubKeyId !== '' && $this->payPubKeyPath !== '') {
                $config['platform_certs'][$this->payPubKeyId] = $this->payPubKeyPath;
            }
            $this->payApp = new Pay($config);
        }

        return $this->payApp;
    }
}
