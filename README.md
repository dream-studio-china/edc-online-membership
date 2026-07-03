# CRUD Skeleton

A production-oriented Symfony 8.1 API skeleton with reusable service-layer abstractions, modular architecture, JWT authentication, dynamic query engine, and pluggable business modules.

> Chinese version: see `README.zh-cn.md`

> Documentation site: [GitHub Pages](https://immane.github.io/crud-skeleton) | Design contracts: [docs/design/](docs/design/)

## Table of Contents

- [Quick Start Guide](#quick-start-guide)
- [Why This Project](#why-this-project)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [Run Locally](#run-locally)
- [Module Overview](#module-overview)
- [API Endpoints](#api-endpoints)
- [How the Service Layer Works](#how-the-service-layer-works)
- [Dynamic Query System](#dynamic-query-system)
- [Create Your Own CRUD Module](#create-your-own-crud-module)
- [Documentation](#documentation)
- [Testing](#testing)
- [Docker Deployment](#docker-deployment)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Quick Start Guide

For a minimal runnable setup (JWT keys, DB migration, admin user, login/auth test), see [QUICKSTART.md](QUICKSTART.md).

If you are on macOS, commands in the quick start prefer Homebrew PHP (`/opt/homebrew/bin/php`) to avoid CLI version mismatch.

## Why This Project

This repository is designed as a clean foundation for backend CRUD development with Symfony.

Compared with plain generated boilerplate, it provides:

- A shared `BaseService` contract for common entity operations.
- Reusable API view mixins (list/detail/create/update/delete/workflow) via PHP trait composition.
- A practical pattern for keeping controller logic thin and business logic in services.
- Expression-based dynamic queries (`@filter`, `@sort`, `@dql`) compiled to DQL with in-memory fallback.
- Pluggable pricing pipeline and state machine for e-commerce workflows.
- Invoice-based payment framework with gateway abstraction (mock, wallet, wechat) and pluggable payment adjustment providers.
- Atomic wallet transfers with deadlock prevention, optimistic locking, idempotency, and wallet balance deduction as a payment adjustment provider.
- Pluggable file storage drivers (local, Qiniu Kodo) with a unified `MediaStorageInterface`.
- JWT authentication (RS256) with refresh token rotation and phone-based OTP login.
- **Password self-registration** with user profile management and admin user CRUD.
- Comprehensive design contracts for consistent new module creation.
- **Wallet balance verification** and reconciliation — ensures `SUM(wallets) == SUM(deposits)` at all times.

## Features

- **CRUD Service Abstraction**: `new()`, `get()`, `list()`, `update()`, `remove()`.
- **Dynamic Query System**: Filter, sort, order, select, group by via request parameters with expression-to-DQL compilation.
- **Trait-Based Controller Composition**: 9 mixin traits (List, Detail, Create, Update, Delete, Workflow, Singleton, Transform) composed into controllers.
- **Modular Architecture**: Core framework + Common (CMS) + Trade (E-Commerce) + Payment + Wallet + Wechat (Login + Pay) + Storage (file upload drivers) + Identity (Auth) modules.
- **JWT Authentication**: RS256 access tokens, HMAC-SHA256 refresh token rotation with reuse detection.
- **OTP Login**: Phone-based one-time password via Alibaba Cloud SMS, rate-limited.
- **Password Registration**: Self-service sign-up with email/username/phone uniqueness validation.
- **User Management**: App profile endpoints + admin CRUD with password management.
- **Order State Machine**: Symfony Workflow for order lifecycle (draft → completed), with workflow API endpoints.
- **Price Calculation Pipeline**: Pluggable calculators with priority ordering for e-commerce order pricing.
- **Atomic Wallet Transfers**: Deadlock prevention (consistent lock ordering), optimistic locking, idempotency via reference ID.
- **Payment Adjustment Providers**: Pre-payment hooks (e.g., wallet deduction) reduce invoice amounts before gateway processing — gateways receive explicit amounts only.
- **Wallet Accounting**: System-injected deposits with audit trail, balance verification (`SUM(wallets) == SUM(deposits)`), per-wallet reconciliation.
- **Wallet Balance Deduction**: Wallet-owned deduction lifecycle with Payment adjustment provider pattern — Payment orchestrates, Wallet implements.
- **Pluggable File Storage**: `MediaStorageInterface` with local and Qiniu Kodo drivers — tagged iterator auto-discovery.
- **OpenAPI Documentation**: NelmioApiDocBundle with `#[OA\*]` attributes, Swagger UI at `/api/doc`.
- **System Introspection**: Entity metadata and route export endpoints (`/system/*`).
- **Comprehensive Testing**: ~110+ test files, 1069 tests, ~3666 assertions, 87.83% coverage.
- **Docker Compose**: MySQL 8 + Mailpit for development.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP `>= 8.4` |
| Framework | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| Database | MySQL 8 (Docker/prod) / SQLite (test) |
| Auth | JWT (RS256) + OTP (SMS) |
| API Docs | NelmioApiDocBundle (OpenAPI 3) |
| Testing | PHPUnit `^12.5` |
| Frontend | Stimulus + Turbo (AssetMapper) |
| Docs | MkDocs Material (GitHub Pages) |

See `composer.json` for the full dependency list.

## Project Structure

```text
.
├── src/
│   ├── Core/                     # Framework core
│   │   ├── Controller/           #   RestController (base API controller)
│   │   ├── Service/              #   BaseService, ExpressionService, QueryBuilderFactory
│   │   ├── Service/Concern/      #   Traits: Infrastructure, ReadList, Mutation
│   │   ├── View/                 #   9 controller mixin traits
│   │   ├── Parser/               #   Expression → DQL compiler
│   │   ├── Serializer/           #   FlatNormalizer, CircularReferenceHandler
│   │   ├── EventListener/        #   ExceptionInterceptor, ControllerListener
│   │   └── Utils/                #   UUID, Math, RSA, ArrayCommon, etc.
│   ├── Common/                   # CMS module (7 entities)
│   │   ├── Controller/App/       #   Public read-only endpoints
│   │   ├── Controller/Manage/    #   Admin CRUD endpoints
│   │   ├── Entity/               #   Category, Tag, Content, Comment, Page, Media, Setting
│   │   ├── Repository/
│   │   └── Service/
│   ├── Trade/                    # E-Commerce module
│   │   ├── Controller/App/       #   Product, Order, Specification listings
│   │   ├── Controller/Manage/    #   Product, Specification, Order (CRUD + workflow)
│   │   ├── Entity/               #   Product, Specification, Order, OrderItem
│   │   ├── Service/              #   OrderService, price calculation pipeline
│   │   └── Service/Pricing/      #   PriceCalculatorInterface + 3 implementations
│   ├── Wallet/                    # Wallet module
│   │   ├── Controller/Manage/    #   Wallet, Transaction, Transfer (deposit) endpoints
│   │   ├── DTO/                  #   WalletPaymentDeductionRequest
│   │   ├── Entity/               #   Wallet, WalletTransaction, WalletPaymentDeduction
│   │   ├── Repository/           #   + WalletPaymentDeductionRepository
│   │   └── Service/              #   TransferService, WalletService
│   │       └── Payment/          #   WalletGateway, WalletBalanceAdjustmentProvider, WalletPaymentDeductionService
│   ├── Payment/                  # Payment module
│   │   ├── Controller/App/       #   Invoice list/detail/pay
│   │   ├── Controller/Manage/    #   Invoice create/cancel/refund/transitions
│   │   ├── Controller/Webhook/   #   Provider payment notification
│   │   ├── DTO/                  #   CreateInvoiceRequest, PaymentResult, PaymentAdjustmentContext/Result, etc.
│   │   ├── Entity/               #   Invoice (cents, workflow, gateway)
│   │   ├── Event/                #   InvoicePaid, Refunded, Cancelled, Failed
│   │   ├── Exception/            #   GatewayNotFound, Verification, Transition
│   │   ├── Repository/
│   │   └── Service/              #   InvoiceService, PaymentGatewayRegistry
│   │       ├── Adjustment/       #   PaymentAdjustmentProviderInterface, PaymentAdjustmentRegistry
│   │       └── Gateway/          #   MockGateway
│   ├── Wechat/                   # WeChat module
│   │   ├── Controller/           #   LoginController (Mini Program + OAuth)
│   │   ├── Controller/App/       #   WechatUser CRUD (user-scoped)
│   │   ├── Controller/Manage/    #   WechatUser CRUD (admin)
│   │   ├── Entity/               #   WechatUser (OneToOne→User)
│   │   ├── Repository/
│   │   └── Service/              #   WechatService, WechatAuthService, WechatUserService
│   │       └── Payment/          #   WechatPayGateway
│   ├── Storage/                  # Storage module (pluggable file upload drivers)
│   │   ├── Service/              #   MediaStorageInterface, MediaStorageRegistry
│   │   │   ├── LocalStorage.php       # Local filesystem (public/uploads/)
│   │   │   └── QiniuStorage.php       # Qiniu Kodo CDN
│   │   └── Resources/config/     #   services_storage.yaml
│   └── Identity/                 # Authentication module
│       ├── Controller/           #   AuthController, OtpController
│       ├── Controller/App/       #   UserController (profile, change-password)
│       ├── Controller/Manage/    #   UserController (admin CRUD)
│       ├── Command/              #   CreateUserCommand (CLI)
│       ├── Entity/               #   User, RefreshToken
│       ├── Security/             #   JwtAuthenticator, TokenManager
│       └── Service/              #   UserService (register, password management), OtpService, SMS providers
├── config/                       # Symfony configuration
│   └── packages/                 #   Doctrine, Security, Workflow, Serializer, etc.
├── migrations/                   # Doctrine migrations (8 versions)
├── tests/                        # ~110+ PHPUnit test files (1069 tests, ~3666 assertions)
├── docs/                         # Project documentation
│   ├── design/                   #   Design contracts (system, API, data, module, controller)
│   │   └── bundles/              #   Per-module design documents
│   └── ai/                       #   AI context snapshot
├── compose.yaml                  # MySQL 8
├── compose.override.yaml         # Port mapping + Mailpit
└── mkdocs.yml                    # MkDocs Material configuration
```

## Getting Started

### 1) Clone

```bash
git clone https://github.com/immane/crud-skeleton.git
cd crud-skeleton
```

### 2) Install dependencies

```bash
composer install
```

### 3) Prepare environment for native PHP

Docker development works without creating an env file. For native PHP/Symfony, create local overrides in `.env.local`:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4"
JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=
REFRESH_TOKEN_SECRET=change-this-secret
```

## Configuration

Environment file roles:

| File | Purpose | Commit? |
|------|---------|---------|
| `.env` | Committed Symfony defaults, no secrets | Yes |
| `.env.dev`, `.env.test` | Committed environment defaults for dev/test | Yes |
| `.env.local`, `.env.*.local` | Machine-local overrides and secrets | No |
| `.env.example` | Local development variable reference | Yes |
| `.env.prod.example` | Production Docker template | Yes |
| `.env.prod.local` | Real production Docker values | No |

Important variables:

| Variable | Purpose |
|----------|---------|
| `APP_ENV` | Environment (`dev`/`prod`/`test`) |
| `APP_SECRET` | Symfony application secret |
| `DATABASE_URL` | MySQL connection string |
| `JWT_PRIVATE_KEY_PATH` | RS256 private key |
| `JWT_PUBLIC_KEY_PATH` | RS256 public key |
| `JWT_PASSPHRASE` | Key passphrase |
| `REFRESH_TOKEN_SECRET` | HMAC-SHA256 secret |
| `MAILER_DSN` | Mailer transport |

For production, do not store secrets in committed files. Use real environment variables or `docker compose --env-file .env.prod.local`.

### Media Storage and Qiniu

Media upload supports multiple storage drivers through `App\Storage\Service\MediaStorageInterface`.

| Driver | Status | Notes |
|--------|--------|-------|
| `local` | Built in | Default driver. Stores files under `public/uploads/{YYYYMM}/...` and returns `/uploads/...` paths. |
| `qiniu` | Optional | Qiniu Kodo driver. Requires the Qiniu PHP SDK and `common_setting` records. |

The default upload driver is controlled by:

```dotenv
MEDIA_STORAGE_DEFAULT=local
```

You can override the driver per upload by sending a multipart form field named `storage`:

```bash
curl -X POST http://localhost:8080/api/v1/manage/media/upload \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/photo.jpg" \
  -F "storage=qiniu"
```

#### Enable Qiniu

The Qiniu SDK is intentionally not required by default. Install it only on deployments that use `storage=qiniu`:

```bash
composer require qiniu/php-sdk
```

With Docker:

```bash
docker compose exec app composer require qiniu/php-sdk
```

For production compose commands, include your production compose files and env file:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app composer require qiniu/php-sdk
```

Qiniu credentials are read from `common_setting`, not from `.env`. Create these settings before using the driver:

| Key | Value |
|-----|-------|
| `qiniu.access_key` | Qiniu access key |
| `qiniu.secret_key` | Qiniu secret key |
| `qiniu.bucket` | Bucket name |
| `qiniu.domain` | Public bucket domain, for example `https://cdn.example.com` |

Use the console command to create any missing keys without overwriting existing values:

```bash
php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

With Docker:

```bash
docker compose exec app php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

Alternatively, create the settings through the manage settings API:

```bash
curl -X POST http://localhost:8080/api/v1/manage/settings \
  -H "Authorization: Bearer <admin-token>" \
  -H "Content-Type: application/json" \
  -d '[
    {"key":"qiniu.access_key","value":"<access-key>","type":"string","groupName":"storage","label":"Qiniu Access Key"},
    {"key":"qiniu.secret_key","value":"<secret-key>","type":"string","groupName":"storage","label":"Qiniu Secret Key"},
    {"key":"qiniu.bucket","value":"<bucket>","type":"string","groupName":"storage","label":"Qiniu Bucket"},
    {"key":"qiniu.domain","value":"https://cdn.example.com","type":"string","groupName":"storage","label":"Qiniu Domain"}
  ]'
```

If `storage=qiniu` is used without the SDK installed, the API returns a clear runtime error asking you to install `qiniu/php-sdk`.

## Run Locally

### Option A: Native PHP/Symfony

```bash
symfony server:start
```

or

```bash
php -S 127.0.0.1:8000 -t public
```

### Option B: Docker development

For local development with all services (app, nginx, MySQL, Redis, Mailpit):

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

The app runs at `http://localhost:${APP_PORT:-8080}`.

## Module Overview

| Module | Namespace | Purpose | Key Features |
|--------|-----------|---------|--------------|
| **Core** | `App\Core` | Framework foundation | RestController, BaseService, View mixins, Expression parser |
| **Common** | `App\Common` | CMS | Category (tree), Tag, Content, Comment (polymorphic), Page, Media, Setting (KV) |
| **Trade** | `App\Trade` | E-Commerce | Product + Specification, Order (state machine), Price pipeline |
| **Wallet** | `App\Wallet` | Payments & deduction | Balance (cents), Atomic transfers, System deposits, Idempotency, Wallet balance deduction adjustment provider, Balance verification + reconciliation |
| **Payment** | `App\Payment` | Invoicing & orchestration | Invoice (cents + workflow), Gateway abstraction (mock/wallet/wechat), **Payment adjustment provider contract**, Webhooks, Events |
| **Wechat** | `App\Wechat` | WeChat integration | Mini Program/Official Account login, WeChat Pay V3, WechatUser (OneToOne→User) |
| **Storage** | `App\Storage` | File upload drivers | `MediaStorageInterface`, LocalStorage, QiniuStorage, tagged iterator auto-discovery |
| **Identity** | `App\Identity` | Authentication | JWT (RS256), OTP (SMS), Refresh token rotation, Password registration, User profile/CRUD |

## API Endpoints

### Identity (`/api/auth`)

| Method | Path | Description |
|--------|------|-------------|
| **POST** | **`/api/auth/register`** | **Password self-registration → tokens** |
| POST | `/api/auth/login` | Identifier + password login |
| POST | `/api/auth/otp/request` | Request OTP via SMS |
| POST | `/api/auth/otp/verify` | Verify OTP |
| POST | `/api/auth/token/refresh` | Rotate refresh token |
| POST | `/api/auth/logout` | Revoke tokens |

### User (`/api/v1/app/users`, `/api/v1/manage/users`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/users/me` | Current user profile |
| PUT | `/api/v1/app/users/me` | Update own profile |
| POST | `/api/v1/app/users/change-password` | Change own password |
| GET/POST/PUT/DELETE | `/api/v1/manage/users[/{id}]` | Admin user CRUD |
| POST | `/api/v1/manage/users/{id}/change-password` | Admin change any password |

### Common — App (public read-only)

| Method | Path |
|--------|------|
| GET | `/api/v1/app/categories` |
| GET | `/api/v1/app/categories/{id}` |
| GET | `/api/v1/app/contents` |
| GET | `/api/v1/app/contents/{id}` |
| GET | `/api/v1/app/tags` |
| GET | `/api/v1/app/comments` |
| GET | `/api/v1/app/pages` |
| GET | `/api/v1/app/media` |
| GET | `/api/v1/app/settings` |

### Common — Manage (admin CRUD, ROLE_ADMIN)

| Method | Path |
|--------|------|
| GET/POST | `/api/v1/manage/{resource}` |
| GET/PUT/DELETE | `/api/v1/manage/{resource}/{id}` |
| POST | `/api/v1/manage/{resource}/batch-update` |

Resources: `categories`, `contents`, `tags`, `comments`, `pages`, `media`, `settings`

### Trade

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active products |
| **GET** | **`/api/v1/app/specifications`** | **Browse all active specs** |
| **GET** | **`/api/v1/app/specifications/by-product/{id}`** | **Specs by product** |
| **GET** | **`/api/v1/app/specifications/{id}`** | **Spec detail** |
| GET | `/api/v1/app/orders` | List user's orders |
| GET/POST/PUT/DELETE | `/api/v1/manage/products[/{id}]` | Product CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/specifications[/{id}]` | Specification CRUD |
| POST | `/api/v1/manage/orders` | Create order (with pricing) |
| GET | `/api/v1/manage/orders/todo` | Orders with available transitions |
| GET | `/api/v1/manage/orders/{id}/transitions` | Enabled workflow transitions |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition |
| POST | `/api/v1/app/orders/{id}/payment` | Start order payment |
| POST | `/api/v1/manage/orders/{id}/payment` | Admin start order payment |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund order via linked invoice |

### Wallet

| Method | Path | Description |
|--------|------|-------------|
| GET/POST/PUT/DELETE | `/api/v1/manage/wallets[/{id}]` | Wallet CRUD |
| **GET** | **`/api/v1/manage/wallets/balance`** | **Verify accounting invariant** |
| **POST** | **`/api/v1/manage/wallets/reconcile`** | **Per-wallet reconciliation** |
| GET | `/api/v1/manage/transactions` | List transactions |
| POST | `/api/v1/manage/transfers` | Atomic transfer |
| **POST** | **`/api/v1/manage/transfers/deposit`** | **System deposit (funding)** |

### Payment

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/invoices` | List user's invoices |
| GET | `/api/v1/app/invoices/{id}` | Invoice detail |
| POST | `/api/v1/app/invoices/{id}/pay/{payment}` | Pay invoice via gateway |
| GET | `/api/v1/manage/invoices` | Admin list invoices |
| POST | `/api/v1/manage/invoices` | Create invoice |
| POST | `/api/v1/manage/invoices/{id}/pay/{payment}` | Admin pay invoice |
| POST | `/api/v1/manage/invoices/{id}/cancel` | Cancel unpaid invoice |
| POST | `/api/v1/manage/invoices/{id}/refund` | Refund paid invoice |
| GET | `/api/v1/manage/invoices/{id}/transitions` | Available workflow transitions |
| POST | `/api/payment/notify/{payment}` | Provider callback (webhook) |

### Wechat (`/api/wechat`)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/wechat/miniapp/login` | Mini Program login (`js_code` → JWT) |
| POST | `/api/wechat/miniapp/phone` | Bind WeChat phone number |
| GET | `/api/wechat/oauth/url` | Official Account OAuth redirect URL |
| POST | `/api/wechat/oauth/callback` | OAuth callback (`code` → JWT) |
| GET | `/api/v1/app/wechat-users` | User-scoped WechatUser CRUD |
| GET | `/api/v1/manage/wechat-users` | Admin WechatUser CRUD |

### System Introspection (`/system`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/system/entities` | List all Doctrine entity FQCNs |
| GET | `/system/entities/{entityName}` | Entity field + association metadata |
| GET | `/system/router` | List all registered routes |

### Example request

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/manage/contents" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{"title":"Hello","body":"World"}'
```

### Response format

All endpoints return a unified JSON envelope:

```json
{
  "data": {},
  "code": 200,
  "message": "SUCCESS",
  "paginator": {
    "page": 1,
    "limit": 20,
    "pages": 5,
    "total": 100
  }
}
```

## How the Service Layer Works

`BaseService` composes focused traits under `src/Core/Service/Concern`:

- **`BaseServiceInfrastructureTrait`**
  - EntityManager/repository/logger/serializer access
  - Request stack and validator helpers
  - Transaction wrapper (`wrapInTransaction`)
  - Expression service and legacy evaluator lazy creation
- **`BaseServiceReadListTrait`**
  - `get()` and `list()` behavior
  - QueryBuilder-based listing, request-driven filters/order/group/select
  - DQL compilation via `ExpressionDqlParser` with in-memory fallback
- **`BaseServiceMutationTrait`**
  - `new()`, `update()`, `remove()`
  - Relation/date mapping handling and metadata extraction
  - Symfony Serializer integration for scalar fields
  - Symfony Validator integration

Public interface compatibility is preserved through `BaseServiceInterface`.

## Dynamic Query System

The `list()` method supports these query parameters:

| Parameter | Description | Example |
|-----------|-------------|---------|
| `page` | Page number | `1` |
| `limit` | Items per page | `20` |
| `@filter` | Expression WHERE clause | `entity.status == "active"` |
| `@dql` | Raw DQL sub-query | `(entity.price > 100)` |
| `@order` | Sort fields | `createdAt\|DESC` |
| `@select` | DQL SELECT override | `entity.id, entity.name` |
| `@sort` | In-memory sort fallback | `item.getPrice()` |
| `@expands` | Nested expansion | `category,tags` |
| `@display` | Field projection | `complex` / `reduce` |

Filter expressions support: `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`, `matches` (REGEXP), and chained attributes (`entity.getCategory().getName()`).

## Create Your Own CRUD Module

See **[Module Design Contract](docs/design/module-design.md)** for the full specification.

Quick steps:

1. Create a Doctrine entity in `src/{Module}/Entity`.
2. Create a service class extending `BaseService` + implementing `{Name}ServiceInterface`.
3. Create a repository extending `ServiceEntityRepository`.
4. Create App (public read) and Manage (admin CRUD) controllers using API mixins.
5. Register routes in `config/routes.yaml`.
6. Create a Doctrine migration.

Minimal controller example:

```php
namespace App\Common\Controller\App;

use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;

class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    protected ?string $serviceClass = ContentServiceInterface::class;

    public function __construct(
        protected readonly ContentServiceInterface $service
    ) {}
}
```

Note on controller construction: Controllers extending `RestController` receive `RequestStack`, `SerializerInterface`, and `TranslatorInterface` via `#[Required]` setter injection. You only need to declare module-specific dependencies in your constructor.

## Documentation

- **[Design Contracts](docs/design/)** — System architecture, API design, data model, module design, controller contract, cross-cutting contracts
- **[Bundle Design Docs](docs/design/bundles/)** — Per-module design documents (Core, Common, Trade, Wallet, Identity)
- **[AI Context](docs/ai/context.md)** — Full codebase snapshot for AI-assisted development
- **[API Docs](/api/doc)** — Interactive Swagger UI (when running locally)
- **[QUICKSTART.md](QUICKSTART.md)** — 5-10 minute setup guide

## Testing

Run all tests:

```bash
./vendor/bin/phpunit
```

Run a single test:

```bash
./vendor/bin/phpunit tests/Core/Service/BaseServiceUnitTest.php
```

With coverage (CI enforces minimum coverage):

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

`phpunit.dist.xml` is preconfigured with `APP_ENV=test` and `KERNEL_CLASS=App\Kernel`.

## Docker Deployment

### Architecture

```
                ┌──────────────┐
   :8080  ──────│    nginx     │────── /api/* ──────┐
                └──────────────┘                     │
                                                    ▼
                                            ┌──────────────┐
                                            │  PHP-FPM 8.4 │
                                            │   (app)      │
                                            └──────┬───────┘
                                                   │
                      ┌────────────────────────────┼────────────────────┐
                      │                            │                    │
                ┌─────▼─────┐              ┌──────▼──────┐      ┌──────▼──────┐
                │  MySQL 8   │              │    Redis 7   │      │   Mailpit   │
                │            │              │  (OTP/cache) │      │ (email dev) │
                └───────────┘              └─────────────┘      └─────────────┘
```

| Service | Image | Container | Purpose |
|---------|-------|-----------|---------|
| **nginx** | `nginx:alpine` | reverse proxy | Routes requests to PHP-FPM, serves static files |
| **app** | built from `Dockerfile` | PHP-FPM 8.4 | Symfony application |
| **database** | `mysql:8.4` | MySQL 8 | Persistent data storage |
| **redis** | `redis:7-alpine` | Redis 7 | OTP storage, cache (optional: OTP fallbacks to local cache) |
| **mailer** | `axllent/mailpit` | Mailpit | Catches outgoing emails, UI at mailpit port |

### Development

```bash
# One command to start everything. No env file is required for local Docker dev.
docker compose up -d --build

# First-run: migrate DB and create admin
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# App → http://localhost:8080   Swagger → http://localhost:8080/api/doc
```

What happens under the hood:
- `docker/app/entrypoint.sh` creates development JWT keys once under the mounted `./var/jwt` directory, then reuses them
- `compose.override.yaml` auto-loads with dev settings (`APP_ENV=dev`, `APP_DEBUG=1`)
- `compose.yaml` supplies safe development defaults for required secrets
- All optional features (WeChat, SMS) are disabled by default — enable them with `.env` or `--env-file`

If you need to customize Docker ports, database credentials, or optional integrations, create a Docker env file and pass it explicitly:

```bash
cp .env.example .env.docker.local
docker compose --env-file .env.docker.local up -d --build
```

Do not put production secrets in the committed `.env` file.

### Production

#### Step 1: Prepare production env file

```bash
cp .env.prod.example .env.prod.local
```

Edit `.env.prod.local` and set at least:

```dotenv
APP_SECRET=your-64-char-random-secret
REFRESH_TOKEN_SECRET=your-32-byte-random-secret
MYSQL_PASSWORD=your-database-password
MYSQL_ROOT_PASSWORD=your-root-database-password
DEFAULT_URI=https://api.example.com
```

Optional integrations can stay empty. Empty WeChat/SMS variables disable those features.

#### Step 2: Generate JWT keys on host

Keys are persisted outside the container via the `./var` bind mount:

```bash
mkdir -p var/jwt
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
chmod 600 var/jwt/jwt_private.pem
```

> If your private key has a passphrase, set `JWT_PASSPHRASE` in `.env.prod.local`.

#### Step 3: Start

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
```

#### Step 4: Initialize

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

#### Step 5: Verify

```bash
curl -s http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

### Environment Variables Reference

**Required for production**:

| Variable | Purpose |
|----------|---------|
| `APP_SECRET` | Symfony application secret |
| `REFRESH_TOKEN_SECRET` | HMAC-SHA256 key for refresh tokens |
| `MYSQL_PASSWORD` | MySQL application user password |
| `MYSQL_ROOT_PASSWORD` | MySQL root password |

**Provided by compose.yaml** (development defaults, override for production as needed):

| Variable | Docker default |
|----------|----------------|
| `DATABASE_URL` | `mysql://app:...@database:3306/app` |
| `MAILER_DSN` | `smtp://mailer:1025` |
| `OTP_REDIS_DSN` | `redis://redis:6379/0` |
| `JWT_PRIVATE_KEY_PATH` | `/var/www/html/var/jwt/jwt_private.pem` |
| `JWT_PUBLIC_KEY_PATH` | `/var/www/html/var/jwt/jwt_public.pem` |

**Optional** (leave empty to disable the feature):

| Feature | Variables (see `.env.example` or `.env` for full list) |
|---------|----------------------------------------------------------|
| Aliyun SMS | `ALIYUN_ACCESS_KEY_ID`, `ALIYUN_ACCESS_KEY_SECRET`, ... |
| WeChat Mini Program | `WECHAT_MINIAPP_APP_ID`, `WECHAT_MINIAPP_SECRET` |
| WeChat Official Account | `WECHAT_OFFICIAL_APP_ID`, `WECHAT_OFFICIAL_SECRET`, ... |
| WeChat Pay V3 | `WECHAT_PAY_MCH_ID`, `WECHAT_PAY_SECRET_KEY`, ... |

### Useful Commands

The commands below are for Docker development. For production, add `-f compose.yaml -f compose.prod.yaml --env-file .env.prod.local` after `docker compose`.

```bash
# View logs
docker compose logs -f app

# Run a Symfony command
docker compose exec app php bin/console about

# Open a shell in the app container
docker compose exec app bash

# Clear Symfony cache
docker compose exec app php bin/console cache:clear

# Check which migrations are pending
docker compose exec app php bin/console doctrine:migrations:status

# Stop everything
docker compose down

# Reset and restart (WARNING: deletes all data)
docker compose down -v && docker compose up -d --build
```

### Custom nginx Configuration

Replace `docker/nginx/default.conf` with your own config. Common changes:
- Add TLS/SSL certificates and listen on 443
- Change `server_name` to your domain
- Add rate limiting or IP whitelisting

Then rebuild:
```bash
docker compose up -d --build nginx
```

### Upgrading

Development:

```bash
git pull
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console cache:clear
```

Production:

```bash
git pull
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console cache:clear
```

## Troubleshooting

### PHPUnit says your PHP version is too old

The project dependencies require modern PHP (`>= 8.4`). Ensure your CLI matches.

### Database connection errors

- Verify `DATABASE_URL`.
- Ensure MySQL is running (`docker compose ps`).
- Ensure DB user/password/dbname match compose environment.

### Empty responses or serialization issues

Check serializer service wiring and request parameters like `@display`, `@expands`, `@filter`.

### Authentication 401

- Run `QUICKSTART.md` steps to generate JWT keys.
- Verify `Authorization: Bearer {token}` header.
- Check token hasn't expired (default 7200s).

## Contributing

1. Fork and create a feature branch.
2. Follow the [design contracts](docs/design/) for consistency.
3. Keep pull requests focused.
4. Add/update tests for behavior changes.
5. Use conventional commit messages (e.g., `feat(module): description`).

## License

Apache-2.0. See [LICENSE](LICENSE) for details.
