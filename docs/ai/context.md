# CRUD Skeleton - Full Codebase Context

> Auto-generated context snapshot. Last updated: 2025-06-21

---

## 1. Project Overview

**CRUD Skeleton** is a Symfony 8.1 API backend skeleton with:
- **PHP 8.4+**, Doctrine ORM 3.6, PostgreSQL 16
- JWT authentication (RS256), OTP/SMS login
- Expression-based dynamic query engine (`@filter`, `@sort`, `@dql`)
- Modular architecture: **Core** (framework), **Common** (CMS), **Identity** (auth), **Trade** (e-commerce), **Wallet** (payments)
- NelmioApiDoc (Swagger at `/api/doc`), PHPUnit 12.5, Docker Compose

## 2. Directory Structure

```
├── public/index.php              # Front controller
├── src/Kernel.php                # Symfony Kernel (MicroKernelTrait)
├── bin/console                   # CLI entry point
│
├── src/Core/                     # Framework core (controllers, services, views, serializers, utils)
│   ├── Controller/RestController.php    # Base API controller (success/warning/pagination)
│   ├── View/                     # PHP traits: List, Detail, Create, Update, Delete, Workflow mixins
│   ├── Service/BaseService.php          # Abstract CRUD service (get/list/new/update/remove)
│   ├── Service/Concern/                 # Traits: Infrastructure, ReadList, Mutation
│   ├── Parser/ExpressionDqlParser.php   # Expression → DQL compiler
│   ├── Serializer/FlatNormalizer.php    # Custom object normalizer
│   ├── EventListener/                   # ExceptionInterceptor, ControllerListener
│   └── Utils/                           # UUID, Math, RSA, Location, Inflect, etc.
│
├── src/Common/                   # CMS module: Category, Tag, Content, Comment, Page, Media, Setting
│   ├── Entity/                   # 7 entities with PHP8 attributes
│   ├── Repository/               # ServiceEntityRepository subclasses
│   ├── Service/                  # Services extending BaseService
│   └── Controller/App/ + Manage/ # Public read-only + Admin CRUD controllers
│
├── src/Identity/                 # Authentication & Identity
│   ├── Entity/User.php                 # User (email/username/phone login)
│   ├── Entity/RefreshToken.php        # Refresh token with rotation
│   ├── Security/JwtAuthenticator.php  # Symfony custom authenticator
│   ├── Security/TokenManager.php      # JWT access/refresh token lifecycle
│   ├── Service/OtpService.php         # 6-digit OTP with SMS
│   └── Controller/AuthController.php  # login, OTP, refresh, logout
│
├── src/Trade/                    # E-commerce module
│   ├── Entity/                   # Product, Specification, Order, OrderItem
│   ├── Service/OrderService.php        # Order creation with price calculation pipeline
│   ├── Service/Pricing/                # PriceCalculatorInterface (Base, Quantity, Total)
│   └── Controller/App/ + Manage/       # Public + Admin endpoints
│
├── src/Wallet/                   # Wallet module
│   ├── Entity/                   # Wallet (balance in cents, optimistic locking), WalletTransaction
│   ├── Service/TransferService.php     # Atomic wallet-to-wallet transfer with deadlock prevention
│   └── Controller/Manage/              # Wallet, Transaction, Transfer controllers
│
├── config/                       # Symfony config (services.yaml, routes.yaml, packages/*.yaml)
├── migrations/                   # 5 Doctrine migrations
├── assets/                       # JS/CSS (Stimulus, Turbo) via AssetMapper
├── templates/base.html.twig      # Minimal Twig base
├── tests/                        # ~79 PHPUnit test files
├── compose.yaml                  # PostgreSQL 16 + Mailpit via Docker
└── .github/workflows/ci.yml      # CI: PHP 8.4, 80% coverage requirement
```

## 3. Request Lifecycle

1. `public/index.php` → `App\Kernel` (MicroKernelTrait)
2. `config/routes.yaml` imports route prefixes:
   - Identity: `#[Route('/api/auth')]` directly on `AuthController`
   - Common/Trade/Wallet: `#[Route]` attribute auto-discovery under `/api/v1`
3. `JwtAuthenticator` intercepts all `/api` routes (except public paths)
4. Controller action → `BaseService` methods → Doctrine EntityManager → PostgreSQL
5. `RestController::success()` / `RestController::warning()` → JSON response (`{data, code, message, paginator}`)

## 4. Authentication Flow

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/auth/login` | POST | Email/username/phone + password → `{access_token, refresh_token}` |
| `/api/auth/otp/request` | POST | Request 6-digit OTP via SMS (Alibaba Cloud) |
| `/api/auth/otp/verify` | POST | Verify OTP → tokens (login) or mark phone verified |
| `/api/auth/token/refresh` | POST | Rotate refresh token (old revoked, new issued) |
| `/api/auth/logout` | POST | Revoke access token + optional refresh token |

**Token management**: RS256 JWT access tokens (60min TTL), HMAC-SHA256 hashed refresh tokens with rotation + reuse detection.

## 5. Dynamic Query System

`BaseServiceReadListTrait::list()` supports these query parameters:

| Param | Description | Example |
|-------|-------------|---------|
| `@filter` | Expression-based WHERE (compiled to DQL by `ExpressionDqlParser`) | `entity.status == "active"` |
| `@dql` | Raw DQL sub-query | `(entity.price > 100)` |
| `@order` | ORDER BY | `createdAt\|DESC` |
| `@select` | DQL SELECT | `entity.id, entity.name` |
| `@groupBy` | GROUP BY | `entity.category` |
| `@sort` | In-memory sort expression | `item.getPrice()` |
| `@expands` | Nested object expansion | `category,tags` |
| `@display` | Field projection | `complex`, `reduce`, or custom mapping |
| `@transform` | Expression-based field transformation (create/update) | `Math.mul(value, 100)` |
| `@hints` | Query hints | |
| `page`, `limit` | Pagination | `page=1&limit=20` |

**Expression syntax**: Supports `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`, `matches` (REGEXP), chained attributes (`entity.getCategory().getName()`), custom functions (`Math`, `ArrayCommon`, `FilterDateTime`).

**Fallback**: If DQL compilation fails for `@filter`/`@sort`, falls back to in-memory evaluation via `LegacyEvaluator` + Symfony `ExpressionLanguage`.

## 6. BaseService Architecture

```
BaseService (abstract)
├── BaseServiceInfrastructureTrait    # EM, Logger, Serializer, Validator, Transactions
├── BaseServiceReadListTrait          # get(), list() with dynamic queries
└── BaseServiceMutationTrait          # new(), update(), updateWithoutListener(), remove()
```

- **`get(mixed $criteria)`**: Accepts QueryBuilder, entity object, array criteria, or scalar ID
- **`list(array $params)`**: Full dynamic query with pagination
- **`update()`**: Handles ManyToOne/OneToOne (by ID), ManyToMany/OneToMany (add/remove by ID), date-like fields, then Serializer for remaining scalars
- **`remove()`**: Find → remove → flush
- **Transaction**: `wrapInTransaction(callable $fn)` with commit/rollback

## 7. Key Patterns

| Pattern | Where | Detail |
|---------|-------|--------|
| **Trait mixins** | View layer | `ListApiViewMixin`, `CreateApiViewMixin`, etc. composed into controllers |
| **Field whitelisting** | Controllers | `$requiredCreateProperties`, `$acceptedCreateProperties`, `$acceptedUpdateProperties` |
| **Money in cents** | Wallet + Trade | All amounts stored as `bigint` cents, converted ×/÷100 |
| **UUID v4** | Trade + Wallet | `App\Core\Utils\UUID::v4()` for external identity |
| **Soft delete** | Trade | `isDeleted` boolean on Product, Specification |
| **Snapshot** | Trade | `OrderItem` captures `specSnapshot`/`productSnapshot` at creation |
| **State machine** | Trade | Symfony Workflow for Order: draft→pending→confirmed→paid→fulfilled→completed |
| **Token rotation + reuse detection** | Identity | Refresh tokens hashed, rotated; reuse → all user tokens revoked |
| **Idempotency** | Wallet | `referenceId` unique constraint on WalletTransaction |
| **Pipeline** | Trade | `PriceCalculatorInterface` with `getPriority()` ordering |
| **Optimistic locking** | Wallet | `$version` field via `#[ORM\Version]` |

## 8. Database Tables (5 Migrations)

| Version | Tables |
|---------|--------|
| 20250514000000 | `users`, `common_content` |
| 20250515000001 | `identity_refresh_token` |
| 20250516000000 | `common_category`, `common_tag`, `common_content_tag`, `common_media`, `common_page`, `common_comment`, `common_setting` |
| 20250517000000 | `wallet`, `wallet_transaction` |
| 20250620000000 | `trade_product`, `trade_specification`, `trade_order`, `trade_order_item` |

## 9. API Structure

### Identity (`/api/auth`)
- `POST /login`, `POST /otp/request`, `POST /otp/verify`, `POST /token/refresh`, `POST /logout`

### Common Public (read-only, no auth required)
- `GET /api/v1/app/categories`, `GET /api/v1/app/contents`, etc.
- Each App controller may override `commonFilter()` (e.g., Category filters `enabled=true`)

### Common Admin (`ROLE_ADMIN` required)
- `GET/POST/PUT/DELETE /api/v1/manage/categories/{id}`
- `POST /api/v1/manage/contents/batch-update` (upsert mode with `?@mode=mixed`)

### Trade Public
- `GET /api/v1/app/orders`, `GET /api/v1/app/products`

### Trade Admin
- `GET/POST/PUT/DELETE /api/v1/manage/orders`, `/products`, `/specifications`
- `POST /api/v1/manage/orders` → custom create with price calculation
- `GET /api/v1/manage/orders/todo` → state machine tasks
- `POST /api/v1/manage/orders/{id}/do/{transition}` → workflow transitions
- `PUT /api/v1/manage/orders/{id}/status-reset` → reset state machine

### Wallet Admin
- `GET/POST/PUT/DELETE /api/v1/manage/wallets`, `/transactions`
- `POST /api/v1/manage/transfer` → atomic wallet-to-wallet transfer

## 10. Testing

- **Framework**: PHPUnit 12.5 with `Symfony\Component\Panther` + `Symfony\Bundle\FrameworkBundle\Test`
- **DB**: SQLite `var/test.db` in test environment
- **Coverage**: 80% minimum (enforced in CI)
- **Key test bases**: `IntegrationWebTestCase` (API functional tests), `IntegrationKernelTestCase` (kernel + DB access), `DatabaseBootstrapTrait` (schema creation/seeding)
- **~79 test files** covering: entity unit tests, service unit tests, API integration/regression tests, pricing, transfers, auth, OTP, token lifecycle

## 11. Environment Variables (Key)

| Var | Purpose |
|-----|---------|
| `APP_ENV`, `APP_DEBUG` | Symfony environment |
| `DATABASE_URL` | PostgreSQL connection |
| `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH` | RS256 key pair |
| `JWT_PASSPHRASE` | Private key passphrase |
| `JWT_REFRESH_TOKEN_SECRET` | HMAC-SHA256 secret |
| `OTP_REDIS_DSN` | Redis for OTP storage (prod) |
| `ALIYUN_SMS_*` | Alibaba Cloud SMS credentials |

## 12. Console Commands

| Command | Module |
|---------|--------|
| `app:identity:user:create` | Identity | Create user: email, username, password, --phone, --role, --admin, --phone-verified |

## 13. Service Container Wiring

- Default: all `src/` classes autowired/autoconfigured
- Explicit exclusions (manually wired in `services.yaml`): `FlatNormalizer` (decorates `serializer.normalizer.object`), event listeners, Auth/Otp controllers, TokenManager, AliyunSmsProvider, RedisOtpStorage
- `RestController` subclasses get `RequestStack`, `SerializerInterface`, `TranslatorInterface` via setter injection using `#[Required]` attribute
- `PriceCalculatorInterface` implementations auto-tagged `trade.price_calculator`, sorted by priority
