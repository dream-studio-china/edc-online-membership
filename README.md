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
- [Docker Notes](#docker-notes)
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
- Invoice-based payment framework with gateway abstraction (mock, wallet, future providers).
- Atomic wallet transfers with deadlock prevention, optimistic locking, and idempotency.
- JWT authentication (RS256) with refresh token rotation and phone-based OTP login.
- Comprehensive design contracts for consistent new module creation.

## Features

- **CRUD Service Abstraction**: `new()`, `get()`, `list()`, `update()`, `remove()`.
- **Dynamic Query System**: Filter, sort, order, select, group by via request parameters with expression-to-DQL compilation.
- **Trait-Based Controller Composition**: 9 mixin traits (List, Detail, Create, Update, Delete, Workflow, Singleton, Transform) composed into controllers.
- **Modular Architecture**: Core framework + Common (CMS) + Trade (E-Commerce) + Payment + Wallet + Wechat (Login + Pay) + Identity (Auth) modules.
- **JWT Authentication**: RS256 access tokens, HMAC-SHA256 refresh token rotation with reuse detection.
- **OTP Login**: Phone-based one-time password via Alibaba Cloud SMS, rate-limited.
- **Order State Machine**: Symfony Workflow for order lifecycle (draft → completed), with workflow API endpoints.
- **Price Calculation Pipeline**: Pluggable calculators with priority ordering for e-commerce order pricing.
- **Atomic Wallet Transfers**: Deadlock prevention (consistent lock ordering), optimistic locking, idempotency via reference ID.
- **OpenAPI Documentation**: NelmioApiDocBundle with `#[OA\*]` attributes, Swagger UI at `/api/doc`.
- **System Introspection**: Entity metadata and route export endpoints (`/system/*`).
- **Comprehensive Testing**: ~80+ test files, 917 tests, ~3150 assertions, 85.50% coverage.
- **Docker Compose**: PostgreSQL 16 + Mailpit for development.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP `>= 8.4` |
| Framework | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| Database | PostgreSQL 16 (prod) / SQLite (test) |
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
│   │   ├── Controller/App/       #   Product, Order listings
│   │   ├── Controller/Manage/    #   Product, Specification, Order (CRUD + workflow)
│   │   ├── Entity/               #   Product, Specification, Order, OrderItem
│   │   ├── Service/              #   OrderService, price calculation pipeline
│   │   └── Service/Pricing/      #   PriceCalculatorInterface + 3 implementations
│   ├── Wallet/                   # Wallet module
│   │   ├── Controller/Manage/    #   Wallet, Transaction, Transfer endpoints
│   │   ├── Entity/               #   Wallet, WalletTransaction
│   │   └── Service/              #   TransferService (atomic transfers)
│   ├── Payment/                  # Payment module
│   │   ├── Controller/App/       #   Invoice list/detail/pay
│   │   ├── Controller/Manage/    #   Invoice create/cancel/refund/transitions
│   │   ├── Controller/Webhook/   #   Provider payment notification
│   │   ├── DTO/                  #   CreateInvoiceRequest, PaymentResult, etc.
│   │   ├── Entity/               #   Invoice (cents, workflow, gateway)
│   │   ├── Event/                #   InvoicePaid, Refunded, Cancelled, Failed
│   │   ├── Exception/            #   GatewayNotFound, Verification, Transition
│   │   ├── Repository/
│   │   └── Service/              #   InvoiceService, PaymentGatewayRegistry
│   │       └── Gateway/          #   MockGateway, WalletGateway, WechatPayGateway
│   ├── Wechat/                   # WeChat module
│   │   ├── Controller/           #   LoginController (Mini Program + OAuth)
│   │   ├── Controller/App/       #   WechatUser CRUD (user-scoped)
│   │   ├── Controller/Manage/    #   WechatUser CRUD (admin)
│   │   ├── Entity/               #   WechatUser (OneToOne→User)
│   │   ├── Repository/
│   │   └── Service/              #   WechatService, WechatAuthService, WechatUserService
│   │       └── Gateway/          #   WechatPayGateway
│   └── Identity/                 # Authentication module
│       ├── Controller/           #   AuthController, OtpController
│       ├── Entity/               #   User, RefreshToken
│       ├── Security/             #   JwtAuthenticator, TokenManager
│       └── Service/              #   OtpService, SMS providers
├── config/                       # Symfony configuration
│   └── packages/                 #   Doctrine, Security, Workflow, Serializer, etc.
├── migrations/                   # Doctrine migrations (7 versions)
├── tests/                        # ~80+ PHPUnit test files (917 tests, ~3150 assertions)
├── docs/                         # Project documentation
│   ├── design/                   #   Design contracts (system, API, data, module, controller)
│   │   └── bundles/              #   Per-module design documents
│   └── ai/                       #   AI context snapshot
├── compose.yaml                  # PostgreSQL 16
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

### 3) Prepare environment

Create your local overrides in `.env.local`:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

## Configuration

Important environment variables (see `.env` and `.env.example`):

| Variable | Purpose |
|----------|---------|
| `APP_ENV` | Environment (`dev`/`prod`/`test`) |
| `APP_SECRET` | Symfony application secret |
| `DATABASE_URL` | PostgreSQL connection string |
| `JWT_PRIVATE_KEY_PATH` | RS256 private key |
| `JWT_PUBLIC_KEY_PATH` | RS256 public key |
| `JWT_PASSPHRASE` | Key passphrase |
| `JWT_REFRESH_TOKEN_SECRET` | HMAC-SHA256 secret |
| `MAILER_DSN` | Mailer transport |

For production, do not store secrets in committed files.

## Run Locally

### Option A: Native PHP/Symfony

```bash
symfony server:start
```

or

```bash
php -S 127.0.0.1:8000 -t public
```

### Option B: Database with Docker Compose

```bash
docker compose up -d
```

Then run DB migrations:

```bash
php bin/console doctrine:migrations:migrate
```

## Module Overview

| Module | Namespace | Purpose | Key Features |
|--------|-----------|---------|--------------|
| **Core** | `App\Core` | Framework foundation | RestController, BaseService, View mixins, Expression parser |
| **Common** | `App\Common` | CMS | Category (tree), Tag, Content, Comment (polymorphic), Page, Media, Setting (KV) |
| **Trade** | `App\Trade` | E-Commerce | Product + Specification, Order (state machine), Price pipeline |
| **Wallet** | `App\Wallet` | Payments | Balance (cents), Atomic transfers, Idempotency, Optimistic locking |
| **Payment** | `App\Payment` | Invoicing | Invoice (cents + workflow), Gateway abstraction (mock/wallet/wechat), Webhooks, Events |
| **Wechat** | `App\Wechat` | WeChat integration | Mini Program/Official Account login, WeChat Pay V3, WechatUser (OneToOne→User) |
| **Identity** | `App\Identity` | Authentication | JWT (RS256), OTP (SMS), Refresh token rotation |

## API Endpoints

### Identity (`/api/auth`)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/login` | Identifier + password login |
| POST | `/api/auth/otp/request` | Request OTP via SMS |
| POST | `/api/auth/otp/verify` | Verify OTP |
| POST | `/api/auth/token/refresh` | Rotate refresh token |
| POST | `/api/auth/logout` | Revoke tokens |

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
| GET | `/api/v1/manage/transactions` | List transactions |
| POST | `/api/v1/manage/transfer` | Atomic transfer |

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

With coverage (CI enforces 85% minimum):

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

`phpunit.dist.xml` is preconfigured with `APP_ENV=test` and `KERNEL_CLASS=App\Kernel`.

## Docker Deployment

### Production

```bash
# Build and start all services
docker compose up -d

# Run database migration
docker compose exec app php bin/console doctrine:migrations:migrate

# Generate JWT keys (in app container)
docker compose exec app mkdir -p var
docker compose exec app php -r '
  $key = openssl_pkey_new(["private_key_bits"=>2048,"private_key_type"=>OPENSSL_KEYTYPE_RSA]);
  openssl_pkey_export($key, $priv);
  file_put_contents("var/jwt_private.pem", $priv);
  file_put_contents("var/jwt_public.pem", openssl_pkey_get_details($key)["key"]);
'

# Create admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

### Development

```bash
# Dev mode overrides (mounts source, enables debug, exposes ports)
docker compose -f compose.yaml -f compose.override.yaml up -d --build
```

### Services

| Service | Port | Description |
|---------|------|-------------|
| nginx | `${APP_PORT:-8080}` | API gateway |
| app | — | PHP-FPM 8.4 |
| database | `5432` (dev) | PostgreSQL 16 |
| redis | — | Redis 7 (OTP/session) |
| mailer | `${MAILPIT_UI_PORT:-8025}` | Mailpit (email testing) |

## Docker Notes

The repository includes:

- `compose.yaml` - Production: app (PHP-FPM), nginx, PostgreSQL 16, Redis 7, Mailpit
- `compose.override.yaml` - Dev overrides (source mounting, debug, exposed ports)
- `Dockerfile` - PHP 8.4-FPM Alpine with required extensions

Default exposed ports:

- PostgreSQL: `5432`
- Mailpit SMTP: `1025`
- Mailpit UI: `8025`

## Troubleshooting

### PHPUnit says your PHP version is too old

The project dependencies require modern PHP (`>= 8.4`). Ensure your CLI matches.

### Database connection errors

- Verify `DATABASE_URL`.
- Ensure PostgreSQL is running (`docker compose ps`).
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

MIT. See [LICENSE](LICENSE) for details.
