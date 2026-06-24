<?php

declare(strict_types=1);

namespace App\Tests\Wechat\Service;

use App\Wechat\Service\WechatService;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use Overtrue\Socialite\Contracts\ProviderInterface;
use PHPUnit\Framework\TestCase;

final class WechatServiceTest extends TestCase
{
    private WechatService $service;

    protected function setUp(): void
    {
        $this->service = new WechatService(
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
    }

    public function testConstructorStoresConfiguration(): void
    {
        self::assertInstanceOf(WechatService::class, $this->service);
    }

    public function testGetMiniAppReturnsCachedInstance(): void
    {
        $app1 = $this->service->getMiniApp();
        $app2 = $this->service->getMiniApp();
        self::assertSame($app1, $app2);
    }

    public function testGetOfficialAccountReturnsCachedInstance(): void
    {
        $app1 = $this->service->getOfficialAccount();
        $app2 = $this->service->getOfficialAccount();
        self::assertSame($app1, $app2);
    }

    public function testGetOAuthRedirectUrlReturnsString(): void
    {
        $url = $this->service->getOAuthRedirectUrl('https://example.com/callback');
        self::assertIsString($url);
        self::assertStringStartsWith('https://', $url);
        self::assertStringContainsString('appid=wx_off', $url);
        self::assertStringContainsString('snsapi_userinfo', $url);
    }

    public function testCode2SessionSuccess(): void
    {
        $mockData = ['openid' => 'o_mock', 'unionid' => 'u_mock', 'session_key' => 'sk_mock'];
        $this->mockMiniAppGet($mockData);

        $result = $this->service->code2Session('js_code_test');
        self::assertSame('o_mock', $result['openid']);
        self::assertSame('u_mock', $result['unionid']);
        self::assertSame('sk_mock', $result['session_key']);
    }

    public function testCode2SessionWithoutUnionid(): void
    {
        $mockData = ['openid' => 'o_no_union', 'session_key' => 'sk2'];
        $this->mockMiniAppGet($mockData);

        $result = $this->service->code2Session('js_code2');
        self::assertSame('o_no_union', $result['openid']);
        self::assertNull($result['unionid']);
        self::assertSame('sk2', $result['session_key']);
    }

    public function testCode2SessionError(): void
    {
        $mockData = ['errcode' => 40029, 'errmsg' => 'invalid code'];
        $this->mockMiniAppGet($mockData);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('invalid code');

        $this->service->code2Session('invalid_code');
    }

    public function testGetPhoneNumberSuccess(): void
    {
        $mockData = ['errcode' => 0, 'phone_info' => ['phoneNumber' => '+8613800138000']];
        $this->mockMiniAppPostJson($mockData);

        $result = $this->service->getPhoneNumber('phone_code');
        self::assertSame('+8613800138000', $result['phoneNumber']);
    }

    public function testGetPhoneNumberError(): void
    {
        $mockData = ['errcode' => 40029, 'errmsg' => 'invalid code'];
        $this->mockMiniAppPostJson($mockData);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('invalid code');

        $this->service->getPhoneNumber('bad_code');
    }

    public function testGetOAuthUserError(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('userFromCode')
            ->willThrowException(new \RuntimeException('OAuth failed'));

        $officialAccount = $this->createMock(OfficialAccount::class);
        $officialAccount->method('getOAuth')->willReturn($provider);

        $this->service->setOfficialAccount($officialAccount);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat OAuth failed');

        $this->service->getOAuthUser('bad_code');
    }

    public function testSetMiniAppOverridesCachedInstance(): void
    {
        $miniApp = $this->createMock(MiniApp::class);
        $this->service->setMiniApp($miniApp);
        self::assertSame($miniApp, $this->service->getMiniApp());
    }

    public function testSetOfficialAccountOverridesCachedInstance(): void
    {
        $oa = $this->createMock(OfficialAccount::class);
        $this->service->setOfficialAccount($oa);
        self::assertSame($oa, $this->service->getOfficialAccount());
    }

    private function mockMiniAppGet(array $responseData): void
    {
        $miniApp = $this->createMock(MiniApp::class);
        $client = $this->createMock(\EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient::class);

        $response = $this->createMock(\EasyWeChat\Kernel\HttpClient\Response::class);
        $response->method('toArray')->willReturn($responseData);

        $account = $this->createMock(\EasyWeChat\MiniApp\Account::class);
        $account->method('getAppId')->willReturn('wx_mini');
        $account->method('getSecret')->willReturn('sec_mini');
        $miniApp->method('getAccount')->willReturn($account);
        $miniApp->method('getClient')->willReturn($client);
        $client->method('get')->willReturn($response);

        $this->service->setMiniApp($miniApp);
    }

    private function mockMiniAppPostJson(array $responseData): void
    {
        $miniApp = $this->createMock(MiniApp::class);
        $client = $this->createMock(\EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient::class);

        $response = $this->createMock(\EasyWeChat\Kernel\HttpClient\Response::class);
        $response->method('toArray')->willReturn($responseData);

        $miniApp->method('getClient')->willReturn($client);
        $client->method('postJson')->willReturn($response);

        $this->service->setMiniApp($miniApp);
    }
}
