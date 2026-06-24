# CRUD Skeleton - Full Codebase Context

> Auto-generated context snapshot. Last updated: 2025-06-25

---

## 1. Project Overview

**CRUD Skeleton** is a Symfony 8.1 API backend skeleton with:
- **PHP 8.4+**, Doctrine ORM 3.6, PostgreSQL 16
- JWT authentication (RS256), OTP/SMS login, WeChat Mini Program / Official Account login
- Expression-based dynamic query engine (`@filter`, `@sort`, `@dql`)
- Modular architecture: **Core** (framework), **Common** (CMS), **Identity** (auth), **Trade** (e-commerce), **Payment** (invoices), **Wallet** (balances), **Wechat** (login + pay)
- EasyWeChat 6.x integration (Mini Program, Official Account OAuth, WeChat Pay V3)
- NelmioApiDoc (Swagger at `/api/doc`), PHPUnit 12.5, Docker Compose
- MkDocs Material + GitHub Pages documentation

## 2. Directory Structure

```
├── public/index.php              # Front controller
├── src/Kernel.php                # Symfony Kernel (MicroKernelTrait)
├── bin/console                   # CLI entry point
│
├── src/Core/                     # Framework core
│   ├── Controller/RestController.php    # Base API controller (success/warning/pagination)
│   ├── Controller/System/               # System introspection (EntityController, RouterController)
│   ├── View/                     # PHP traits: List, Detail, Create, Update, Delete, Workflow, Single, Transform
│   ├── Service/BaseService.php          # Abstract CRUD service
│   ├── Service/Concern/                 # Traits: Infrastructure, ReadList, Mutation
│   ├── Parser/ExpressionDqlParser.php   # Expression → DQL compiler
│   ├── Serializer/FlatNormalizer.php    # Custom object normalizer (Doctrine internal objects → class names)
│   ├── EventListener/                   # ExceptionInterceptor, ControllerListener, OpenApiEnricherListener
│   └── Utils/                           # UUID, Math, RSA, Location, Inflect, etc.
│
├── src/Common/                   # CMS module: Category, Tag, Content, Comment, Page, Media, Setting
│   ├── Entity/                   # 7 entities
│   ├── Repository/
│   ├── Service/
│   └── Controller/App/ + Manage/
│
├── src/Identity/                 # Authentication & Identity
│   ├── Entity/User.php, RefreshToken.php
│   ├── Security/JwtAuthenticator.php, TokenManager.php
│   ├── Service/OtpService.php, SMS providers
│   └── Controller/AuthController.php, OtpController.php
│
├── src/Trade/                    # E-commerce module
│   ├── Entity/                   # Product, Specification, Order, OrderItem
│   ├── Service/OrderService.php        # pay(), refund(), fulfill() + price pipeline
│   ├── Service/Pricing/                # PriceCalculatorInterface (Base, Quantity, Total)
│   ├── EventListener/OrderWorkflowListener.php
│   ├── Exception/                      # OrderInvalidTransitionException, SpecificationNotFoundException
│   └── Controller/App/ + Manage/       # CRUD + workflow + pay/refund/fulfill + items + cancel
│
├── src/Payment/                  # Payment module
│   ├── Entity/Invoice.php              # Payment invoice (pending→paying→paid→refunded)
│   ├── DTO/                            # PaymentResult, PaymentNotifyResult, PaymentRefundResult
│   ├── Event/                          # InvoicePaidEvent, InvoiceRefundedEvent, etc.
│   ├── Service/PaymentGatewayInterface.php  # Gateway contract (pay, notify, refund)
│   ├── Service/Gateway/               # MockGateway, WalletGateway, WechatPayGateway (auto-registered)
│   ├── Service/PaymentGatewayRegistry.php  # #[AutowireIterator('payment.gateway')] registry
│   └── Controller/App/ + Manage/ + Webhook/
│
├── src/Wallet/                   # Wallet module
│   ├── Entity/                   # Wallet (balance, optimistic locking), WalletTransaction
│   ├── Service/TransferService.php     # Atomic transfer with deadlock prevention + idempotency
│   └── Controller/Manage/
│
├── src/Wechat/                   # WeChat module
│   ├── Entity/WechatUser.php           # OneToOne→User (openid, unionid, sessionKey, profile)
│   ├── Repository/WechatUserRepository.php
│   ├── Service/WechatService.php       # EasyWeChat factory (MiniApp, OfficialAccount, Pay)
│   ├── Service/WechatAuthService.php   # Login orchestration (code2Session/OAuth→User→JWT)
│   ├── Service/WechatUserService.php   # CRUD service (extends BaseService)
│   ├── Service/Gateway/WechatPayGateway.php  # implements PaymentGatewayInterface
│   └── Controller/
│       ├── LoginController.php         # /api/wechat/* (miniapp login, oauth, phone binding)
│       ├── App/WechatUserController.php      # User-scoped CRUD
│       └── Manage/WechatUserController.php   # Admin CRUD
│
├── config/
│   ├── services.yaml             # Service wiring + import src/Wechat/ + exclusions
│   ├── routes.yaml               # Route imports (wechat, wechat_app, wechat_manage added)
│   └── packages/
│       ├── nelmio_api_doc.yaml   # OpenAPI 3.1 config: System + Wechat tags
│       ├── security.yaml         # PUBLIC_ACCESS: /system/*, /api/wechat/miniapp/login, oauth/*
│       ├── workflow.yaml         # Order state machine (draft→completed)
│       └── ...
├── migrations/                   # 7 Doctrine migrations (latest added wechat_user, payment_invoice)
├── docs/
│   ├── ai/context.md             # This file
│   ├── design/                   # Design contracts
│   │   └── bundles/              # Per-module design docs (core, common, trade, wallet, identity, wechat)
│   └── openapi/endpoints.yaml
├── scripts/tests/                # Test scripts
├── tests/                        # ~917 PHPUnit tests, ~3150 assertions
├── mkdocs.yml                    # MkDocs Material config
├── compose.yaml                  # PostgreSQL 16 + Mailpit via Docker
└── .github/workflows/
    ├── ci.yml                    # CI: PHP 8.4, 80% coverage
    └── docs.yml                  # GitHub Pages deploy
```

## 3. Request Lifecycle

1. `public/index.php` → `App\Kernel` (MicroKernelTrait)
2. `config/routes.yaml` imports: `/api/v1` (Common/Trade/Wallet/Payment/Wechat App+Manage), `/api/auth` (Identity), `/api/wechat` (Wechat login), `/system` (introspection), `/api/payment/notify` (webhook)
3. `JwtAuthenticator` intercepts all `/api` routes (except public paths listed in security.yaml)
4. Controller action (trait mixin or custom method) → `BaseService` methods → Doctrine EntityManager → DB
5. `RestController::success()` / `warning()` → JSON `{data, code, message, paginator}`

## 4. Authentication Flow

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/auth/login` | POST | PUBLIC | Email/username/phone + password → `{access_token, refresh_token}` |
| `/api/auth/otp/request` | POST | PUBLIC | Request 6-digit OTP via SMS (Alibaba Cloud) |
| `/api/auth/otp/verify` | POST | PUBLIC | Verify OTP → tokens or mark phone verified |
| `/api/auth/token/refresh` | POST | PUBLIC | Rotate refresh token (old revoked, new issued) |
| `/api/auth/logout` | POST | PUBLIC | Revoke access token + optional refresh token |
| `/api/wechat/miniapp/login` | POST | PUBLIC | WeChat Mini Program `js_code` → JWT tokens |
| `/api/wechat/oauth/url` | GET | PUBLIC | Official Account OAuth redirect URL |
| `/api/wechat/oauth/callback` | POST | PUBLIC | OAuth `code` → JWT tokens |
| `/api/wechat/miniapp/phone` | POST | AUTH | Bind WeChat phone number to authenticated user |

**Token management**: RS256 JWT (7200s TTL), HMAC-SHA256 refresh tokens with rotation + reuse detection.

## 5. Dynamic Query System

`BaseServiceReadListTrait::list()` supports:

| Param | Description | Example |
|-------|-------------|---------|
| `@filter` | Expression → DQL WHERE | `entity.status == "active"` |
| `@dql` | Raw DQL sub-query | `(entity.price > 100)` |
| `@order` | ORDER BY | `createdAt\|DESC` |
| `@select` | DQL SELECT | `entity.id, entity.name` |
| `@groupBy` | GROUP BY | `entity.category` |
| `@sort` | In-memory sort | `item.getPrice()` |
| `@expands` | Nested expansion | `category,tags` |
| `@display` | Field projection | `complex`, `reduce` |
| `@transform` | Field transformation | `Math.mul(value, 100)` |
| `page`, `limit` | Pagination | `page=1&limit=20` |

**Expression syntax**: `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`, `matches`, chained attributes, `Math`, `ArrayCommon`, `FilterDateTime` functions. Falls back to in-memory `LegacyEvaluator` when DQL compilation fails.

## 6. BaseService Architecture

```
BaseService (abstract)
├── BaseServiceInfrastructureTrait    # EM, Logger, Serializer, Validator, Transactions
├── BaseServiceReadListTrait          # get(), list() with dynamic queries
└── BaseServiceMutationTrait          # new(), update(), updateWithoutListener(), remove()
```

- **`get(mixed $criteria)`**: QueryBuilder, entity, array criteria, or scalar ID
- **`list(array $params)`**: Dynamic query + pagination
- **`update()`**: Relation mapping (M:1, 1:M, M:M by ID), date fields, Serializer for scalars
- **`remove()`**: Find → remove → flush
- **`wrapInTransaction(callable $fn)`**: Transaction with commit/rollback

## 7. Trade Module — Order Lifecycle

### 7.1 State Machine (workflow.yaml)

```
draft → pending → confirmed → paid → fulfilled → completed → refunded
                          ↳ cancelled (from draft/pending/confirmed)
```

### 7.2 OrderService Methods

| Method | Description |
|--------|-------------|
| `calculatePrices(items, currency)` | Pipeline: BasePriceCalculator → QuantityCalculator → TotalAggregator |
| `createOrder(items, user, total, currency, notes)` | Create Order + OrderItems in transaction |
| `pay(Order, systemWalletId, paymentMethod)` | User wallet → system wallet via `TransferService`. Sets `paidAt`. |
| `refund(Order, systemWalletId, reason)` | System wallet → user wallet via `TransferService`. Sets `refundedAt`. |
| `fulfill(Order, data)` | Set tracking/shipping + `fulfilledAt`. |

### 7.3 Order Entity Fields

| Field | Type | When Set |
|-------|------|----------|
| `paidAt` | DateTimeImmutable | On `pay` transition |
| `refundedAt` | DateTimeImmutable | On `refund` transition |
| `fulfilledAt` | DateTimeImmutable | On `fulfill` transition |
| `paymentMethod` | string | On pay |
| `trackingNumber` | string | On fulfill |
| `shippingAddress` | text | On fulfill |
| `refundReason` | text | On refund |

### 7.4 Manage Order Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/manage/orders` | Create with price calculation |
| PUT | `/manage/orders/{id}` | Update draft only |
| DELETE | `/manage/orders/{id}` | Delete draft only |
| GET | `/manage/orders/{id}/items` | View order items |
| POST | `/manage/orders/{id}/pay` | Wallet payment + transition |
| POST | `/manage/orders/{id}/fulfill` | Fulfill with tracking |
| POST | `/manage/orders/{id}/refund` | Wallet refund + transition |
| GET | `/manage/orders/todo` | Orders with pending transitions |
| GET | `/manage/orders/{id}/transitions` | Available transitions |
| POST | `/manage/orders/{id}/do/{transition}` | Execute generic transition |

### 7.5 App Order Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/app/orders` | Create order (auto-assigns user) |
| GET | `/app/orders/{id}/items` | View own order items |
| POST | `/app/orders/{id}/cancel` | Cancel own order (draft/pending/confirmed) |

## 8. Payment Module

### 8.1 Invoice System

```
Invoice (pending→paying→paid→refunded)
  ├── payment: 'wallet'|'wechat'|'mock'
  ├── scene: 'order'|'deposit'|'wallet_topup'
  ├── amount/currency (cents)
  └── payer (User, nullable)
```

### 8.2 PaymentGatewayInterface — Gateway Registry Pattern

```php
interface PaymentGatewayInterface {
    static getName(): string;           // e.g. 'wallet', 'wechat', 'mock'
    pay(Invoice, array $options): PaymentResult;
    notify(Request $request): PaymentNotifyResult;
    refund(Invoice, int $amount, string $reason, array $options): PaymentRefundResult;
    getNotifySuccessResponse(PaymentNotifyResult $result): Response;
}
```

All implementations auto-tagged `payment.gateway` via `_instanceof` rule. `PaymentGatewayRegistry` uses `#[AutowireIterator('payment.gateway')]` for auto-discovery. Webhook route: `/api/payment/notify/{payment}` (PUBLIC_ACCESS, gateway validates own signature).

### 8.3 Payment Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/app/invoices/{id}/pay/{payment}` | User pays invoice via gateway |
| POST | `/manage/invoices/{id}/pay/{payment}` | Admin pays invoice |
| POST | `/manage/invoices/{id}/cancel` | Cancel invoice |
| POST | `/manage/invoices/{id}/refund` | Refund invoice |
| POST | `/api/payment/notify/{payment}` | Payment callback (public, G/W signature verified) |

## 9. Wechat Module

### 9.1 WechatUser Entity (OneToOne → User)

```
WechatUser (wechat_user) ──OnetoOne──> User (users)
  openid (unique), unionid, sessionKey
  nickname, avatar, sex, province, city, country
  appType ('miniapp' | 'official')
  rawData (json)
```

**User.php is NOT modified** — WechatUser extends identity via OneToOne with CASCADE delete.

### 9.2 Login Flow

```
Mini Program: wx.login() → js_code → POST /api/wechat/miniapp/login
  → WechatService.code2Session() → {openid, unionid, session_key}
  → WechatAuthService.authenticateFromMiniApp()
    ├─ findByOpenid(openid) → hit → update sessionKey → return User
    └─ miss → new User() + new WechatUser() → flush → return User
  → TokenManager.createTokens() → {access_token, refresh_token, expires_in}

Official Account: redirect → oauth code → POST /api/wechat/oauth/callback
  → WechatService.getOAuthUser(code) → {openid, nickname, avatar, ...}
  → WechatAuthService.authenticateFromOfficialAccount()
  → TokenManager.createTokens() → JWT
```

New users get random password (cannot password-login), synthetic email/username from openid.

### 9.3 WechatPayGateway

Implements `PaymentGatewayInterface` with `getName() → 'wechat'`:
- **pay()**: JSAPI (requires payer openid from WechatUser) or Native (QR code)
- **notify()**: EasyWeChat server + validator, signature verification
- **refund()**: Creates refund via WeChat Pay V3 API
- Auto-registered as `payment.gateway` via `_instanceof` rule

### 9.4 WechatUser CRUD Controllers

| Prefix | Auth | Filter | Description |
|--------|------|--------|-------------|
| `/api/v1/app/wechat-users` | ROLE_USER | `['user' => $this->getUser()]` | User-scoped CRUD (only own data) |
| `/api/v1/manage/wechat-users` | ROLE_ADMIN | `[]` (no filter) | Admin CRUD (all records) |

When `$this->getUser()` returns null in App controllers, `commonFilter()` returns `['id' => -1]` to block all records (security: unauthenticated users see nothing).

## 10. System Introspection Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/system/entities` | GET | List all Doctrine entity FQCNs |
| `/system/entities/{entityName}` | GET | Field + association metadata per entity (type, nullable, targetEntity) |
| `/system/router` | GET | List all registered routes |

Placed in `src/Core/Controller/System/` (framework layer). NelmioApiDoc path_patterns include `^/system`. Tag: `System`.

## 11. Key Patterns

| Pattern | Where | Detail |
|---------|-------|--------|
| **Trait mixins** | View layer | 9 PHP traits composed into controllers |
| **Field whitelisting** | Controllers | `$requiredCreateProperties`, `$acceptedCreateProperties`, `$acceptedUpdateProperties` |
| **Money in cents** | Wallet + Trade + Payment | `bigint` cents, API boundary converts ×/÷100 |
| **UUID v4** | Trade + Wallet | `UUID::v4()` for external identity |
| **Soft delete** | Trade | `isDeleted` boolean on Product, Specification |
| **Snapshot** | Trade | `OrderItem` captures `specSnapshot`/`productSnapshot` at creation |
| **State machine** | Trade | Symfony Workflow for orders |
| **Token rotation + reuse detection** | Identity | HMAC-SHA256 refresh tokens |
| **Idempotency** | Wallet | `referenceId` unique constraint on WalletTransaction |
| **Pipeline** | Trade | `PriceCalculatorInterface` with priority ordering |
| **Optimistic locking** | Wallet | `#[ORM\Version]` on Wallet |
| **Post-response enrichment** | Core | `OpenApiEnricherListener` post-processes `/api/doc` and `/api/doc.json` |
| **commonFilter** | Controllers | Array of WHERE criteria injected into all queries. `[]` = no filter (admin), `['user' => $user]` = user-scoped, `['id' => -1]` = block all |
| **Gateway registry** | Payment | `#[AutowireIterator]` + `_instanceof` auto-tags all `PaymentGatewayInterface` implementations |
| **OneToOne extension** | Wechat | `WechatUser` extends User identity without modifying User entity |
| **System introspection** | Core | Entity metadata + route export via `/system/*` endpoints |

## 12. API Documentation System

### 12.1 Architecture

Controller `#[OA\*]` attributes → swagger-php (raw spec) → NelmioApiDocBundle (merge config) → `OpenApiEnricherListener` (post-process) → Swagger UI

### 12.2 OpenApiEnricherListener (`src/Core/EventListener/OpenApiEnricherListener.php`)

Enriches all endpoints (90+):
- **`detectTag()`**: Infers module tag from `operationId`: `manage-products-*` → Products, `system-*` → System, `wechat-*` → Wechat, `sys-auth-*` → Auth, etc.
- **`META`**: Optional summaries/descriptions for key endpoints
- **`ensureTag()`**: Adds dynamically detected tags to the spec's tag list
- **Generic tag removal**: Filters out operation-type tags (List, Detail, Create, Update, Delete, Workflow) from swagger-php output — replaced with module tags
- Registered in `services.yaml` as `kernel.event_listener` on `kernel.response` (priority -10)

### 12.3 Tag Auto-Detection

| operationId Pattern | Tag |
|---------------------|-----|
| `sys-auth-*` | Auth |
| `system-*` | System |
| `wechat-*` | Wechat |
| `manage-products-*`, `app-products-*` | Products |
| `manage-orders-*`, `app-orders-*` | Orders |
| `manage-categories-*`, `app-categories-*` | Categories |
| `manage-tags-*`, `app-tags-*` | Tags |
| `manage-contents-*`, `app-contents-*` | Contents |
| `manage-comments-*`, `app-comments-*` | Comments |
| `manage-pages-*`, `app-pages-*` | Pages |
| `manage-media-*`, `app-media-*` | Media |
| `manage-settings-*`, `app-settings-*` | Settings |
| `manage-wallets-*`, `manage-transactions-*`, `manage-transfers-*` | Wallet |
| Any other `manage-{X}-*` | {X} (auto-title-cased) |

### 12.4 Schema Configuration (`config/packages/nelmio_api_doc.yaml`)

42+ named schemas across 11 tags (Auth, Products, Orders, Categories, Tags, Contents, Comments, Pages, Media, Settings, Wallet, System, Wechat). Each with field-level type, description, enum, and example values. `path_patterns` includes both `^/api` and `^/system`.

## 13. Database Tables (7 Migrations)

| Version | Tables |
|---------|--------|
| 20250514000000 | `users`, `common_content` |
| 20250515000001 | `identity_refresh_token` |
| 20250516000000 | `common_category`, `common_tag`, `common_content_tag`, `common_media`, `common_page`, `common_comment`, `common_setting` |
| 20250517000000 | `wallet`, `wallet_transaction` |
| 20250620000000 | `trade_product`, `trade_specification`, `trade_order`, `trade_order_item` |
| 20250621000000 | Added to `trade_order`: `paid_at`, `refunded_at`, `fulfilled_at`, `payment_method`, `tracking_number`, `shipping_address`, `refund_reason` |
| 20250624223701 | `payment_invoice`, `wechat_user`, `messenger_messages` |

## 14. Documentation Assets

| File | Purpose |
|------|---------|
| `docs/design/system-architecture.md` | Layer rules, module structure, DI contract |
| `docs/design/api-design.md` | Response envelope, URL conventions, HTTP semantics, query params |
| `docs/design/data-model.md` | Entity conventions, naming, relationships, patterns |
| `docs/design/module-design.md` | Module skeleton, file contracts, checklist |
| `docs/design/controller-design.md` | Trait mixin catalog, hook methods, assembly patterns |
| `docs/design/api-documentation.md` | API doc system architecture, enricher contract, new module guide |
| `docs/design/system-contracts.md` | Transactions, errors, logging, security, testing |
| `docs/design/bundles/core.md` | Core framework design |
| `docs/design/bundles/common.md` | CMS module design |
| `docs/design/bundles/trade.md` | E-commerce module design |
| `docs/design/bundles/wallet.md` | Wallet module design |
| `docs/design/bundles/identity.md` | Auth module design |
| `docs/design/bundles/wechat.md` | WeChat module design (Mini Program, Official Account, Pay) |
| `docs/ai/context.md` | This file — AI context snapshot |
| `mkdocs.yml` | MkDocs Material site config |
| `scripts/tests/simulate-trade.php` | Generates 100 orders across all 8 statuses into `var/test.db` |
| `scripts/tests/demo-trade-workflow.php` | E2E workflow demo (all transitions + guards) |

## 15. Testing

- **Framework**: PHPUnit 12.5
- **DB**: SQLite `var/test.db` in test environment
- **Coverage**: 80% minimum (enforced in CI), currently 85.50% lines
- **Test count**: 917 tests, ~3150 assertions
- **Key test groups**:
  - `tests/Wechat/`: 59 tests (Entity, Service, AuthService, Gateway, Controller, Repository)
  - `tests/Trade/`: 171 tests (Entity, Service, Pricing, Integration, EventListener, Workflow API)
  - `tests/Wallet/`: 63 tests (Entity, Integration, Transfer Service, API regression)
  - `tests/Common/`: 68 tests (Entity, Integration, Batch update)
  - `tests/Identity/`: 11 tests (Auth, OTP, Token, Black box)
  - `tests/Payment/`: Integration tests for Invoice + Gateway
  - `tests/Integration/`: ~20 cross-module tests
  - `tests/Core/`: BaseService, RestController, Parser, Serializer, Utils, System controllers

## 16. Environment Variables (Key)

| Var | Purpose |
|-----|---------|
| `APP_ENV`, `APP_DEBUG` | Symfony environment |
| `DATABASE_URL` | DB connection (PostgreSQL/SQLite/MySQL) |
| `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH` | RS256 key pair |
| `JWT_PASSPHRASE` | Private key passphrase |
| `JWT_REFRESH_TOKEN_SECRET` | HMAC-SHA256 secret |
| `OTP_REDIS_DSN` | Redis for OTP storage |
| `ALIYUN_SMS_*` | Alibaba Cloud SMS |
| `WECHAT_MINIAPP_APP_ID`, `WECHAT_MINIAPP_SECRET` | WeChat Mini Program |
| `WECHAT_OFFICIAL_APP_ID`, `WECHAT_OFFICIAL_SECRET`, `WECHAT_OFFICIAL_TOKEN`, `WECHAT_OFFICIAL_AES_KEY` | Official Account |
| `WECHAT_PAY_MCH_ID`, `WECHAT_PAY_SECRET_KEY`, `WECHAT_PAY_PRIVATE_KEY`, `WECHAT_PAY_CERTIFICATE`, `WECHAT_PAY_NOTIFY_URL` | WeChat Pay V3 |
| `MESSENGER_TRANSPORT_DSN` | Async transport |
| `DEFAULT_URI` | Base URL for CLI contexts |
| `MAILER_DSN` | Mailer transport |

## 17. Console Commands

| Command | Module | Purpose |
|---------|--------|---------|
| `app:identity:user:create` | Identity | Create user: email, username, password, --phone, --role, --admin, --phone-verified |

## 18. Service Container Wiring

- Default: all `src/` classes autowired/autoconfigured
- Explicit exclusions: `FlatNormalizer`, EventListener classes (except `OpenApiEnricherListener`), Auth/Otp controllers, TokenManager, AliyunSmsProvider, RedisOtpStorage, **WechatService, WechatPayGateway**
- `OpenApiEnricherListener`: registered with `kernel.event_listener` tag on `kernel.response` (priority -10)
- `RestController` subclasses get `RequestStack`, `SerializerInterface`, `TranslatorInterface` via `#[Required]` setter injection
- `PaymentGatewayInterface` implementations auto-tagged `payment.gateway`, collected via `#[AutowireIterator]`
- `PriceCalculatorInterface` implementations auto-tagged `trade.price_calculator`, sorted by `getPriority()`
- `WechatService` explicitly defined in `services_wechat.yaml` with `%env()` parameter bindings
