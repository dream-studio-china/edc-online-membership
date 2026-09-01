# WeChat Bundle Design

> Created: 2026-06-25

## Overview

独立模块 `src/Wechat/`，实现微信小程序登录、公众号 OAuth 登录、手机号绑定、微信支付全功能。不改动 `User` entity，通过 OneToOne 的 `WechatUser` 扩展用户身份。

---

## Directory Structure

```
src/Wechat/
├── Controller/
│   └── LoginController.php              # Route: /api/wechat
├── Entity/
│   └── WechatUser.php                   # OneToOne → User
├── Repository/
│   └── WechatUserRepository.php
├── Service/
│   ├── Payment/
│   │   └── WechatPayGateway.php         # implements PaymentGatewayInterface
│   ├── WechatAuthService.php            # 登录编排服务
│   └── WechatService.php                # EasyWeChat 三合一工厂
├── Resources/config/
│   └── services_wechat.yaml
```

### Tests

```
tests/Wechat/
├── Entity/
│   └── WechatUserTest.php
├── Service/
│   ├── WechatServiceTest.php
│   └── WechatAuthServiceTest.php
├── Controller/
│   └── LoginControllerTest.php
└── Service/Payment/
    └── WechatPayGatewayTest.php
```

---

## Component Contracts

### Dependency Graph

```
                        ┌──────────────────┐
                        │ EasyWeChat SDK    │
                        │ (w7corp/easywechat)│
                        └────────┬─────────┘
                                 │
                    ┌────────────┴────────────┐
                    │     WechatService        │
                    │  - getMiniApp()          │
                    │  - getOfficialAccount()  │
                    │  - getPayApp()           │
                    │  - code2Session()        │
                    │  - getOAuthUser()        │
                    │  - getPhoneNumber()      │
                    └────────┬────────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐  ┌─────────────────┐  ┌──────────────────┐
│WechatAuthService│  │ LoginController │  │ WechatPayGateway │
│ authenticate() │  │ POST /login     │  │ pay()            │
│ bindPhone()    │  │ POST /phone     │  │ notify()         │
└───────┬───────┘  │ POST /oauth/url │  │ refund()         │
        │          │ POST /oauth/cb   │  └──────────────────┘
        ▼          └────────┬────────┘
┌───────────────┐          │
│WechatUserRepo │          ▼
│UserRepository │  ┌──────────────┐
│EntityManager  │  │ TokenManager │  ← Identity
└───────────────┘  │ (签 JWT)     │
                   └──────────────┘
```

### 对现有系统的侵入度

| 改动 | 文件数 | 说明 |
|------|--------|------|
| 新增文件 | 9 | Wechat 模块全部代码 |
| 修改现有文件 | 5 | composer.json, services.yaml, routes.yaml, security.yaml, Invoice.php |
| **不改动** | `User.php` | 通过 OneToOne 关联 WechatUser，现有 User 零改动 |

---

## Entity: WechatUser

### Table: `wechat_user`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | int | PK, auto | |
| `user_id` | int | FK → users.id, ON DELETE CASCADE | OneToOne 关联 |
| `openid` | string(64) | UNIQUE, NOT NULL | |
| `unionid` | string(64) | nullable | 跨应用统一 ID |
| `session_key` | string(64) | nullable | 仅小程序 |
| `nickname` | string(128) | nullable | 仅公众号 OAuth |
| `avatar` | string(512) | nullable | 仅公众号 OAuth |
| `sex` | int | nullable | 仅公众号 OAuth |
| `province` | string(64) | nullable | 仅公众号 OAuth |
| `city` | string(64) | nullable | 仅公众号 OAuth |
| `country` | string(64) | nullable | 仅公众号 OAuth |
| `app_type` | string(20) | NOT NULL | `miniapp` / `official` |
| `raw_data` | json | nullable | WeChat API 原始响应 |
| `last_login_at` | datetime_immutable | NOT NULL | |
| `created_at` | datetime_immutable | NOT NULL | |
| `updated_at` | datetime_immutable | nullable | |

### Relationship

```
User (users.id) ←── OneToOne ──→ WechatUser (wechat_user.user_id)
    不修改                                         新表
```

### Mapping

```php
#[ORM\Entity(repositoryClass: WechatUserRepository::class)]
#[ORM\Table(name: 'wechat_user')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_user_openid', columns: ['openid'])]
class WechatUser
{
    #[ORM\OneToOne(targetEntity: \App\Identity\Main\Entity\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // getters / setters ...
}
```

---

## Service: WechatService

EasyWeChat Application 工厂，上层通过 Container 参数读取 `%wechat.*%` 配置。

### Constructor Signature

```php
public function __construct(
    // Mini Program
    private readonly string $miniappAppId,
    private readonly string $miniappSecret,

    // Official Account
    private readonly string $officialAppId,
    private readonly string $officialSecret,
    private readonly string $officialToken,
    private readonly string $officialAesKey,

    // WeChat Pay
    private readonly string $payMchId,
    private readonly string $paySecretKey,
    private readonly string $payPrivateKeyPath,
    private readonly string $payCertificatePath,
)
```

### Method Contract

```php
/** 小程序 */
getMiniApp(): \EasyWeChat\MiniApp\Application    // 单例缓存
code2Session(string $jsCode): array              // → {openid, unionid, session_key}
getPhoneNumber(string $code): array              // → {phoneNumber}

/** 公众号 */
getOfficialAccount(): \EasyWeChat\OfficialAccount\Application  // 单例缓存
getOAuthRedirectUrl(string $callbackUrl): string // 生成 snsapi_userinfo 跳转 URL
getOAuthUser(string $code): array                // → {openid, nickname, avatar, sex, province, city, country}

/** 微信支付 */
getPayApp(): \EasyWeChat\Pay\Application          // 单例缓存
```

### AppType Constants

```php
public const APP_TYPE_MINIAPP = 'miniapp';
public const APP_TYPE_OFFICIAL = 'official';
```

---

## Service: WechatAuthService

### Constructor Signature

```php
public function __construct(
    private readonly WechatService $wechatService,
    private readonly WechatUserRepository $wechatUserRepository,
    private readonly UserRepository $userRepository,
    private readonly EntityManagerInterface $em,
)
```

### Method Contract

```php
/** 小程序登录 — js_code → User */
authenticateFromMiniApp(string $jsCode): User

/** 公众号登录 — oauth code → User */
authenticateFromOfficialAccount(string $code): User

/** 绑定手机号（已登录用户） */
bindPhone(User $user, string $code): void
```

### Login Orchestration (internal)

```
1. WechatService.code2Session(jsCode)  → {openid, unionid, session_key}
   WechatService.getOAuthUser(code)    → {openid, nickname, avatar, ...}

2. WechatUserRepository.findByOpenid(openid):
   ├─ Hit  → 更新 sessionKey/nickname/avatar/lastLoginAt
   │         → 返回关联的 User
   └─ Miss → new User()
               email:    "wx_{openid_suffix}@wechat.local"
               username: "wx_{openid_suffix}"
               password: random_bytes(32) + bin2hex  (不可密码登录)
             → new WechatUser(user, openid, ...)
             → em->persist(user), em->persist(wechatUser)

3. em->flush()
4. return User
```

**设计决策：新 User 密码随机** — 微信用户无需密码，直接签发 JWT。密码随机防止通过密码登录漏洞提升权限。

---

## Controller: LoginController

### Class Declaration

```php
#[Route('/api/wechat', name: 'wechat-')]
class LoginController
```

模仿 `AuthController` 模式：不继承 `RestController`，手动返回 `JsonResponse`，有私有 `error()` 方法。

### Constructor Signature

```php
public function __construct(
    private readonly WechatAuthService $wechatAuthService,
    private readonly TokenManager $tokenManager,
    private readonly WechatService $wechatService,
)
```

### Endpoints

#### `POST /api/wechat/miniapp/login` — 小程序登录 (PUBLIC_ACCESS)

```php
Request:  { "js_code": "081abc..." }
Response: { "access_token": "...", "expires_in": 7200, "refresh_token": "..." }

Errors:
  400 — js_code missing
  401 — WeChat API returned error (invalid code)
```

```php
#[Route('/miniapp/login', methods: ['POST'])]
public function miniappLogin(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $jsCode = trim((string) ($data['js_code'] ?? ''));
    if ($jsCode === '') {
        return $this->error('js_code is required.', 400);
    }
    try {
        $user = $this->wechatAuthService->authenticateFromMiniApp($jsCode);
    } catch (\RuntimeException $e) {
        return $this->error($e->getMessage(), 401);
    }
    return $this->tokenResponse($user);
}
```

#### `POST /api/wechat/miniapp/phone` — 绑定手机号 (IS_AUTHENTICATED_FULLY)

```php
Request:  { "code": "xxx" }
Response: 204 No Content

Errors:
  400 — code missing
  401 — WeChat API error
```

```php
#[Route('/miniapp/phone', methods: ['POST'])]
public function miniappPhone(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $code = trim((string) ($data['code'] ?? ''));
    if ($code === '') {
        return $this->error('code is required.', 400);
    }
    try {
        /** @var User $user */
        $user = $this->getUser();
        $this->wechatAuthService->bindPhone($user, $code);
    } catch (\RuntimeException $e) {
        return $this->error($e->getMessage(), 401);
    }
    return new JsonResponse(null, 204);
}
```

#### `GET /api/wechat/oauth/url` — 获取公众号 OAuth 跳转 URL (PUBLIC_ACCESS)

```php
Query:    ?redirect_uri=https://example.com/wechat/callback
Response: { "url": "https://open.weixin.qq.com/..." }

Errors:
  400 — redirect_uri missing
```

```php
#[Route('/oauth/url', methods: ['GET'])]
public function oauthUrl(Request $request): JsonResponse
{
    $redirectUri = trim((string) $request->query->get('redirect_uri', ''));
    if ($redirectUri === '') {
        return $this->error('redirect_uri is required.', 400);
    }
    $url = $this->wechatService->getOAuthRedirectUrl($redirectUri);
    return new JsonResponse(['url' => $url]);
}
```

#### `POST /api/wechat/oauth/callback` — 公众号 OAuth 回调 (PUBLIC_ACCESS)

```php
Request:  { "code": "081abc..." }
Response: { "access_token": "...", "expires_in": 7200, "refresh_token": "..." }
```

```php
#[Route('/oauth/callback', methods: ['POST'])]
public function oauthCallback(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $code = trim((string) ($data['code'] ?? ''));
    if ($code === '') {
        return $this->error('code is required.', 400);
    }
    try {
        $user = $this->wechatAuthService->authenticateFromOfficialAccount($code);
    } catch (\RuntimeException $e) {
        return $this->error($e->getMessage(), 401);
    }
    return $this->tokenResponse($user);
}
```

#### Private helpers

```php
private function tokenResponse(User $user): JsonResponse
{
    return new JsonResponse([
        'access_token'  => $this->tokenManager->createAccessToken($user),
        'expires_in'    => $this->tokenManager->getAccessTtl(),
        'refresh_token' => $this->tokenManager->createRefreshToken($user),
    ]);
}

private function error(string $message, int $status = 400): JsonResponse
{
    return new JsonResponse(['code' => $status, 'message' => $message], $status);
}
```

---

## Gateway: WechatPayGateway

### Contract: implements `PaymentGatewayInterface`

```php
getName() → 'wechat'
```

与 `Invoice::PAYMENT_WECHAT = 'wechat'` 常量一致。

### Constructor Signature

```php
public function __construct(
    private readonly WechatService $wechatService,
    #[Autowire('%wechat.pay.notify_url%')]
    private readonly string $notifyUrl,
)
```

### Method Implementations

#### `pay(Invoice $invoice, array $options = []): PaymentResult`

根据 `$invoice->getTradeType()` 分派：

| tradeType | WeChat API | response |
|-----------|-----------|----------|
| `'jsapi'` | `POST v3/pay/transactions/jsapi` | `payload` = `buildMiniAppConfig(prepayId)` |
| `'native'` | `POST v3/pay/transactions/native` | `payUrl` = `code_url` |

JSAPI 需要 payer openid，通过 `WechatUserRepository` 从 `$invoice->getPayer()` 获取：
```php
$wechatUser = $this->wechatUserRepository->findByUser($invoice->getPayer());
$openid = $wechatUser->getOpenid();
```

#### `notify(Request $request): PaymentNotifyResult`

```php
1. EasyWeChat $payApp->getValidator()->validate($request)
2. $server = $app->getServer()
3. Parse callback body → out_trade_no, transaction_id, amount, status
4. Return PaymentNotifyResult(outTradeNo, status, amount, transactionId, paidAt)
```

#### `refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult`

```php
1. POST v3/refund/domestic/refunds
   { out_trade_no, out_refund_no, amount: { refund, total, currency } }
2. Return PaymentRefundResult(refundId, status)
```

#### `getNotifySuccessResponse(PaymentNotifyResult $result): Response`

```php
return new JsonResponse(['code' => 'SUCCESS', 'message' => '成功']);
```

### 自动注册

`WechatPayGateway` 实现 `PaymentGatewayInterface` 后无需手动配置。`config/services.yaml` 已有的 `_instanceof` 规则：

```yaml
_instanceof:
    App\Payment\Service\PaymentGatewayInterface:
        tags: ['payment.gateway']
```

`PaymentGatewayRegistry` 通过 `#[AutowireIterator('payment.gateway')]` 自动发现。

---

## API 端点汇总

| Method | Path | Auth | Controller Method | Service Call |
|--------|------|------|-------------------|-------------|
| POST | `/api/wechat/miniapp/login` | PUBLIC | `miniappLogin()` | `WechatAuthService::authenticateFromMiniApp()` |
| POST | `/api/wechat/miniapp/phone` | FULLY_AUTH | `miniappPhone()` | `WechatAuthService::bindPhone()` |
| GET | `/api/wechat/oauth/url` | PUBLIC | `oauthUrl()` | `WechatService::getOAuthRedirectUrl()` |
| POST | `/api/wechat/oauth/callback` | PUBLIC | `oauthCallback()` | `WechatAuthService::authenticateFromOfficialAccount()` |
| POST | `/api/payment/notify/wechat` | PUBLIC | (现有 `PaymentNotifyController`) | `WechatPayGateway::notify()` |

---

## 需改动的现有文件

### 1. `composer.json`

```bash
composer require w7corp/easywechat
```

### 2. `config/services.yaml`

```yaml
imports:
    - { resource: '../src/Wechat/Resources/config/services_wechat.yaml', ignore_errors: true }
```

### 3. `config/routes.yaml`

```yaml
wechat:
    prefix: /api/wechat
    resource:
        path: ../src/Wechat/Controller/
        namespace: App\Identity\Wechat\Controller
    type: attribute
```

### 4. `config/packages/security.yaml`

```yaml
access_control:
    # Public WeChat login (before catch-all ^/api)
    - { path: ^/api/wechat/miniapp/login$, roles: PUBLIC_ACCESS }
    - { path: ^/api/wechat/oauth/url$, roles: PUBLIC_ACCESS }
    - { path: ^/api/wechat/oauth/callback$, roles: PUBLIC_ACCESS }
```

### 5. `src/Payment/Entity/Invoice.php`

```php
public const PAYMENT_WECHAT = 'wechat';
```

### 6. `.env`

```ini
# WeChat Mini Program
WECHAT_MINIAPP_APP_ID=
WECHAT_MINIAPP_SECRET=

# WeChat Official Account
WECHAT_OFFICIAL_APP_ID=
WECHAT_OFFICIAL_SECRET=
WECHAT_OFFICIAL_TOKEN=
WECHAT_OFFICIAL_AES_KEY=

# WeChat Pay
WECHAT_PAY_MCH_ID=
WECHAT_PAY_SECRET_KEY=
WECHAT_PAY_PRIVATE_KEY=
WECHAT_PAY_CERTIFICATE=
WECHAT_PAY_NOTIFY_URL=
```

---

## Services Configuration

### `services_wechat.yaml`

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Identity\Wechat\Service\WechatService:
        arguments:
            $miniappAppId: '%env(WECHAT_MINIAPP_APP_ID)%'
            $miniappSecret: '%env(WECHAT_MINIAPP_SECRET)%'
            $officialAppId: '%env(WECHAT_OFFICIAL_APP_ID)%'
            $officialSecret: '%env(WECHAT_OFFICIAL_SECRET)%'
            $officialToken: '%env(WECHAT_OFFICIAL_TOKEN)%'
            $officialAesKey: '%env(WECHAT_OFFICIAL_AES_KEY)%'
            $payMchId: '%env(WECHAT_PAY_MCH_ID)%'
            $paySecretKey: '%env(WECHAT_PAY_SECRET_KEY)%'
            $payPrivateKeyPath: '%env(WECHAT_PAY_PRIVATE_KEY)%'
            $payCertificatePath: '%env(WECHAT_PAY_CERTIFICATE)%'

    App\Payment\Service\Gateway\WechatPayGateway:
        arguments:
            $notifyUrl: '%env(WECHAT_PAY_NOTIFY_URL)%'
```

### 网关自动注册（无需额外配置）

`config/services.yaml` 的 `_instanceof` 规则自动处理：
```yaml
_instanceof:
    App\Payment\Service\PaymentGatewayInterface:
        tags: ['payment.gateway']
```

`PaymentGatewayRegistry` 通过 `#[AutowireIterator('payment.gateway')]` 自动发现所有实现。

---

## Repository: WechatUserRepository

```php
class WechatUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WechatUser::class);
    }

    public function findByOpenid(string $openid): ?WechatUser
    {
        return $this->findOneBy(['openid' => $openid]);
    }

    public function findByUser(User $user): ?WechatUser
    {
        return $this->findOneBy(['user' => $user]);
    }
}
```

---

## OpenAPI Tags & Documentation

沿用 AuthController pattern，`LoginController` 各端点使用 `#[OA\*]` 属性标注，tag 为 `Wechat`。

### NelmioApiDoc 配置更新

`config/packages/nelmio_api_doc.yaml` 新增：
```yaml
- { name: Wechat, description: 'WeChat login, OAuth, and payment' }
```

`OpenApiEnricherListener` 新增 tag 匹配：
```php
if (str_starts_with($opId, 'wechat-')) return 'Wechat';
```

---

## Login Flow Diagrams

### 小程序登录

```
Mini Program                  Backend                         WeChat API
     │                           │                                │
     │  wx.login() → js_code     │                                │
     │──POST /miniapp/login──────>                                │
     │  {js_code}                 │                                │
     │                           │──WechatService.code2Session()──>
     │                           │<──{openid, unionid, session_key}
     │                           │                                │
     │                           │──WechatAuthService             │
     │                           │   .authenticateFromMiniApp()   │
     │                           │  ┌──────────────────┐         │
     │                           │  │ findOrCreateUser  │         │
     │                           │  │  ├ findByOpenid   │         │
     │                           │  │  │ ├ hit→update   │         │
     │                           │  │  │ └ miss→create  │         │
     │                           │  │  └ em->flush()    │         │
     │                           │  └──────────────────┘         │
     │                           │                                │
     │                           │──TokenManager.createTokens()   │
     │<──{access_token, refresh_token, expires_in}                │
     │                           │                                │
```

### 公众号 OAuth

```
Browser                     Backend                    WeChat
   │                          │                          │
   │──GET /oauth/url           │                          │
   │   ?redirect_uri=...──>   │                          │
   │<──{url: "https://open.."}│──                          │
   │                          │                          │
   │──跳转 open.weixin...─────────────────────────────→  │
   │                          │     用户授权              │
   │<──重定向 redirect_uri?code=xxx────────────────── │
   │                          │                          │
   │──POST /oauth/callback    │                          │
   │  {code} ──────────────> │                          │
   │                          │──WechatService           │
   │                          │   .getOAuthUser(code)  ──>
   │                          │<──{openid, nickname, ...}│
   │                          │                          │
   │                          │──WechatAuthService       │
   │                          │   .authenticateFromOff..│
   │                          │                          │
   │<──{access_token, ...}── │                          │
```

---

## Implementation Order

| Step | Files | Description |
|------|-------|-------------|
| 1 | `WechatUser.php` + `WechatUserRepository.php` | Entity + Repository |
| 2 | `WechatService.php` | EasyWeChat 三合一工厂 |
| 3 | `WechatAuthService.php` | 登录编排 |
| 4 | `LoginController.php` | 4 个端点 |
| 5 | `services_wechat.yaml` | DI 配置 |
| 6 | `WechatPayGateway.php` | 支付网关 |
| 7 | config edits | composer, services, routes, security, Invoice, .env |
| 8 | tests | Entity, Service, Controller, Gateway 全覆盖 |
| 9 | API docs | `#[OA\*]` + Nelmio + Enricher 更新 |

---

## Test Coverage Targets

| Class | Coverage Target |
|-------|----------------|
| `WechatUser` | 100% — getter/setter 基础断言 |
| `WechatUserRepository` | 100% — findByOpenid / findByUser |
| `WechatService` | 100% — 三大 Application 工厂、code2Session、getOAuthUser、getPhoneNumber |
| `WechatAuthService` | 100% — 两种登录、bindPhone、新建/复用 User 逻辑 |
| `LoginController` | 100% — 4 端点、错误处理 |
| `WechatPayGateway` | 100% — pay (jsapi/native)、notify、refund、验签 |
