<?php

declare(strict_types=1);

namespace App\Wechat\Service;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;

interface WechatServiceInterface
{
    public function getMiniApp(): MiniApp;

    public function getOfficialAccount(): OfficialAccount;

    public function getPayApp(): Pay;

    /**
     * @internal For testing: inject a pre-configured MiniApp Application
     */
    public function setMiniApp(MiniApp $app): void;

    /**
     * @internal For testing: inject a pre-configured OfficialAccount Application
     */
    public function setOfficialAccount(OfficialAccount $app): void;

    /**
     * @internal For testing: inject a pre-configured Pay Application
     */
    public function setPayApp(Pay $app): void;

    /**
     * Mini Program: code2Session
     * @return array{openid: string, unionid?: string, session_key: string}
     */
    public function code2Session(string $jsCode): array;

    /**
     * Mini Program: get phone number
     * @return array{phoneNumber: string}
     */
    public function getPhoneNumber(string $code): array;

    /**
     * Mini Program: generate unlimited QR code (getwxacodeunlimit)
     *
     * @param string $scene Scene string, max 32 chars (e.g. "id=123")
     * @param string $page Mini Program page, e.g. "pages/index/index"
     * @param int $width Image width in px, 280-1280
     * @return string Raw image bytes (PNG/JPEG)
     */
    public function getMiniProgramUnlimitedCode(
        string $scene,
        string $page = 'pages/index/index',
        int $width = 430,
        bool $checkPath = false,
    ): string;

    /**
     * Official Account: generate OAuth redirect URL
     */
    public function getOAuthRedirectUrl(string $callbackUrl): string;

    /**
     * Official Account: exchange code for user info
     * @return array{openid: string, unionid?: string, nickname: string, avatar: string, sex: int, province: string, city: string, country: string}
     */
    public function getOAuthUser(string $code): array;
}
