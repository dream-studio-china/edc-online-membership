<?php

declare(strict_types=1);

namespace App\Tests\Wechat\Entity;

use App\Identity\Wechat\Entity\WechatUser;
use PHPUnit\Framework\TestCase;

final class WechatUserTest extends TestCase
{
    private string $userUuid;

    protected function setUp(): void
    {
        $this->userUuid = '5a1454b2-2075-4ebf-8fb5-30d18d869b85';
    }

    public function testConstructorSetsRequiredFields(): void
    {
        $wu = new WechatUser($this->userUuid, 'oTest1234', WechatUser::APP_TYPE_MINIAPP);

        self::assertSame($this->userUuid, $wu->getUserUuid());
        self::assertSame('oTest1234', $wu->getOpenid());
        self::assertSame(WechatUser::APP_TYPE_MINIAPP, $wu->getAppType());
        self::assertNotNull($wu->getCreatedAt());
        self::assertNotNull($wu->getLastLoginAt());
        self::assertSame('WechatUser#0 miniapp oTest1234', (string) $wu);
    }

    public function testSettersAndGetters(): void
    {
        $wu = new WechatUser($this->userUuid, 'o1', WechatUser::APP_TYPE_OFFICIAL);

        $wu->setUnionid('u_union_123');
        self::assertSame('u_union_123', $wu->getUnionid());

        $wu->setSessionKey('sk_abc');
        self::assertSame('sk_abc', $wu->getSessionKey());

        $wu->setNickname('TestUser');
        self::assertSame('TestUser', $wu->getNickname());

        $wu->setAvatar('https://example.com/avatar.jpg');
        self::assertSame('https://example.com/avatar.jpg', $wu->getAvatar());

        $wu->setSex(1);
        self::assertSame(1, $wu->getSex());

        $wu->setProvince('Guangdong');
        self::assertSame('Guangdong', $wu->getProvince());

        $wu->setCity('Shenzhen');
        self::assertSame('Shenzhen', $wu->getCity());

        $wu->setCountry('China');
        self::assertSame('China', $wu->getCountry());

        $rawData = ['openid' => 'o1', 'nickname' => 'Test'];
        $wu->setRawData($rawData);
        self::assertSame($rawData, $wu->getRawData());

        $now = new \DateTimeImmutable();
        $wu->setLastLoginAt($now);
        self::assertSame($now, $wu->getLastLoginAt());

        self::assertNotNull($wu->getUpdatedAt());
    }

    public function testOpenidCanBeUpdated(): void
    {
        $wu = new WechatUser($this->userUuid, 'old_openid', WechatUser::APP_TYPE_MINIAPP);
        self::assertNull($wu->getUpdatedAt());

        $wu->setOpenid('new_openid');
        self::assertSame('new_openid', $wu->getOpenid());
        self::assertNotNull($wu->getUpdatedAt());
    }

    public function testConstants(): void
    {
        self::assertSame('miniapp', WechatUser::APP_TYPE_MINIAPP);
        self::assertSame('official', WechatUser::APP_TYPE_OFFICIAL);
    }

    public function testMetadata(): void
    {
        $wu = new WechatUser($this->userUuid, 'o_meta', WechatUser::APP_TYPE_MINIAPP);

        $meta = $wu->__metadata();
        self::assertArrayHasKey('id', $meta);
        self::assertArrayHasKey('userUuid', $meta);
        self::assertSame($this->userUuid, $meta['userUuid']);
        self::assertArrayHasKey('openid', $meta);
        self::assertArrayHasKey('appType', $meta);
        self::assertSame('o_meta', $meta['openid']);
        self::assertSame(WechatUser::APP_TYPE_MINIAPP, $meta['appType']);
    }

    public function testToStringWithNullId(): void
    {
        $wu = new WechatUser($this->userUuid, 'o1', WechatUser::APP_TYPE_OFFICIAL);
        self::assertStringContainsString('WechatUser#0', (string) $wu);
    }
}
