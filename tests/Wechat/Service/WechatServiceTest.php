<?php

declare(strict_types=1);

namespace App\Tests\Wechat\Service;

use App\Wechat\Service\WechatService;
use PHPUnit\Framework\TestCase;

final class WechatServiceTest extends TestCase
{
    public function testConstructorStoresConfiguration(): void
    {
        $service = new WechatService(
            miniappAppId: 'wx_mini',
            miniappSecret: 'sec_mini',
            officialAppId: 'wx_off',
            officialSecret: 'sec_off',
            officialToken: 'tok',
            officialAesKey: 'aes',
            payMchId: '123',
            paySecretKey: 'sk',
            payPrivateKeyPath: '/tmp/key.pem',
            payCertificatePath: '/tmp/cert.pem',
        );

        self::assertInstanceOf(WechatService::class, $service);
    }

    public function testGetMiniAppReturnsCachedInstance(): void
    {
        $service = new WechatService(
            miniappAppId: 'wx_test',
            miniappSecret: 'sec_test',
            officialAppId: 'wx_off',
            officialSecret: 'sec_off',
            officialToken: 'tok',
            officialAesKey: 'aes',
            payMchId: '123',
            paySecretKey: 'sk',
            payPrivateKeyPath: '/tmp/key.pem',
            payCertificatePath: '/tmp/cert.pem',
        );

        $app1 = $service->getMiniApp();
        $app2 = $service->getMiniApp();

        self::assertSame($app1, $app2);
    }

    public function testGetOfficialAccountReturnsCachedInstance(): void
    {
        $service = new WechatService(
            miniappAppId: 'wx_test',
            miniappSecret: 'sec_test',
            officialAppId: 'wx_off',
            officialSecret: 'sec_off',
            officialToken: 'tok',
            officialAesKey: 'aes',
            payMchId: '123',
            paySecretKey: 'sk',
            payPrivateKeyPath: '/tmp/key.pem',
            payCertificatePath: '/tmp/cert.pem',
        );

        $app1 = $service->getOfficialAccount();
        $app2 = $service->getOfficialAccount();

        self::assertSame($app1, $app2);
    }

    public function testGetOAuthRedirectUrlReturnsString(): void
    {
        $service = new WechatService(
            miniappAppId: 'wx_test',
            miniappSecret: 'sec_test',
            officialAppId: 'wx123',
            officialSecret: 'sec456',
            officialToken: 'tok',
            officialAesKey: 'aes',
            payMchId: '123',
            paySecretKey: 'sk',
            payPrivateKeyPath: '/tmp/key.pem',
            payCertificatePath: '/tmp/cert.pem',
        );

        $url = $service->getOAuthRedirectUrl('https://example.com/callback');

        self::assertIsString($url);
        self::assertStringStartsWith('https://', $url);
        self::assertStringContainsString('appid=wx123', $url);
        self::assertStringContainsString('snsapi_userinfo', $url);
    }
}
