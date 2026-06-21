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
- MkDocs Material + GitHub Pages documentation

## 2. Directory Structure

```
├── public/index.php              # Front controller
├── src/Kernel.php                # Symfony Kernel (MicroKernelTrait)
├── bin/console                   # CLI entry point
│
├── src/Core/                     # Framework core
│   ├── Controller/RestController.php    # Base API controller (success/warning/pagination)
│   ├── View/                     # PHP traits: List, Detail, Create, Update, Delete, Workflow, Single, Transform
│   ├── Service/BaseService.php          # Abstract CRUD service
│   ├── Service/Concern/                 # Traits: Infrastructure, ReadList, Mutation
│   ├── Parser/ExpressionDqlParser.php   # Expression → DQL compiler
│   ├── Serializer/FlatNormalizer.php    # Custom object normalizer
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
│   └── Controller/AuthController.php
│
├── src/Trade/                    # E-commerce module
│   ├── Entity/                   # Product, Specification, Order, OrderItem
│   ├── Service/OrderService.php        # pay(), refund(), fulfill() + price pipeline
│   ├── Service/Pricing/                # PriceCalculatorInterface (Base, Quantity, Total)
│   ├── EventListener/OrderWorkflowListener.php
│   ├── Exception/                      # OrderInvalidTransitionException, SpecificationNotFoundException
│   └── Controller/App/ + Manage/       # CRUD + workflow + pay/refund/fulfill + items + cancel
│
├── src/Wallet/                   # Wallet module
│   ├── Entity/                   # Wallet (balance, optimistic locking), WalletTransaction
│   ├── Service/TransferService.php     # Atomic transfer with deadlock prevention + idempotency
│   └── Controller/Manage/
│
├── config/
│   ├── services.yaml             # Service wiring + OpenApiEnricherListener registration
│   ├── routes.yaml               # Route imports
│   └── packages/
│       ├── nelmio_api_doc.yaml   # OpenAPI 3.1 config: 42 schemas, 5 examples, info + tags
│       ├── workflow.yaml         # Order state machine (draft→completed)
│       └── ...
├── migrations/                   # 6 Doctrine migrations (Version20250621000000 added Order fields)
├── docs/
│   ├── ai/context.md             # This file
│   ├── design/                   # Design contracts (system, API, data, module, controller, api-doc, cross-cutting)
│   │   └── bundles/              # Per-module design docs (core, common, trade, wallet, identity)
│   └── openapi/endpoints.yaml    # Standalone complete API reference (60+ endpoints)
├── scripts/tests/                # Test scripts (simulate-trade.php, demo-trade-workflow.php)
├── tests/                        # ~795 PHPUnit tests, 2702 assertions
├── mkdocs.yml                    # MkDocs Material config
├── compose.yaml                  # PostgreSQL 16 + Mailpit via Docker
└── .github/workflows/
    ├── ci.yml                    # CI: PHP 8.4, 80% coverage
    └── docs.yml                  # GitHub Pages deploy
```

## 3. Request Lifecycle

1. `public/index.php` → `App\Kernel` (MicroKernelTrait)
2. `config/routes.yaml` imports route prefixes: Common/Trade/Wallet under `/api/v1`, Identity at `/api/auth`
3. `JwtAuthenticator` intercepts all `/api` routes (except public paths)
4. Controller action (trait mixin or custom method) → `BaseService` methods → Doctrine EntityManager → DB
5. `RestController::success()` / `warning()` → JSON `{data, code, message, paginator}`

## 4. Authentication Flow

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/auth/login` | POST | Email/username/phone + password → `{access_token, refresh_token}` |
| `/api/auth/otp/request` | POST | Request 6-digit OTP via SMS (Alibaba Cloud) |
| `/api/auth/otp/verify` | POST | Verify OTP → tokens or mark phone verified |
| `/api/auth/token/refresh` | POST | Rotate refresh token (old revoked, new issued) |
| `/api/auth/logout` | POST | Revoke access token + optional refresh token |

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

## 8. Key Patterns

| Pattern | Where | Detail |
|---------|-------|--------|
| **Trait mixins** | View layer | 9 PHP traits composed into controllers |
| **Field whitelisting** | Controllers | `$requiredCreateProperties`, `$acceptedCreateProperties`, `$acceptedUpdateProperties` |
| **Money in cents** | Wallet + Trade | `bigint` cents, API boundary converts ×/÷100 |
| **UUID v4** | Trade + Wallet | `UUID::v4()` for external identity |
| **Soft delete** | Trade | `isDeleted` boolean on Product, Specification |
| **Snapshot** | Trade | `OrderItem` captures `specSnapshot`/`productSnapshot` at creation |
| **State machine** | Trade | Symfony Workflow for orders |
| **Token rotation + reuse detection** | Identity | HMAC-SHA256 refresh tokens |
| **Idempotency** | Wallet | `referenceId` unique constraint on WalletTransaction |
| **Pipeline** | Trade | `PriceCalculatorInterface` with priority ordering |
| **Optimistic locking** | Wallet | `#[ORM\Version]` on Wallet |
| **Post-response enrichment** | Core | `OpenApiEnricherListener` post-processes `/api/doc` and `/api/doc.json` |

## 9. API Documentation System

### 9.1 Architecture

Controller `#[OA\*]` attributes → swagger-php (raw spec) → NelmioApiDocBundle (merge config) → `OpenApiEnricherListener` (post-process) → Swagger UI

### 9.2 OpenApiEnricherListener (`src/Core/EventListener/OpenApiEnricherListener.php`)

Single file that enriches all 67 endpoints:
- **`detectTag()`**: Infers module tag from `operationId` (route name): `manage-products-*` → Products, `app-orders-*` → Orders, `sys-auth-*` → Auth, etc.
- **`META`**: Optional summaries/descriptions for key endpoints
- **`ensureTag()`**: Adds dynamically detected tags to the spec's tag list
- Registered in `services.yaml` as `kernel.event_listener` on `kernel.response` (priority -10)
- Intercepts both `/api/doc` (embedded HTML) and `/api/doc.json` (raw JSON)

### 9.3 Tag Auto-Detection

| operationId Pattern | Tag |
|---------------------|-----|
| `sys-auth-*` | Auth |
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

### 9.4 Schema Configuration (`config/packages/nelmio_api_doc.yaml`)

42 named schemas: Order, OrderItem, Product, Specification, Category, Tag, Content, Comment, Page, Media, Setting, Wallet, WalletTransaction, TransferRequest, LoginResponse, UserRef, etc. Each with field-level type, description, enum, and example values.

## 10. Database Tables (6 Migrations)

| Version | Tables |
|---------|--------|
| 20250514000000 | `users`, `common_content` |
| 20250515000001 | `identity_refresh_token` |
| 20250516000000 | `common_category`, `common_tag`, `common_content_tag`, `common_media`, `common_page`, `common_comment`, `common_setting` |
| 20250517000000 | `wallet`, `wallet_transaction` |
| 20250620000000 | `trade_product`, `trade_specification`, `trade_order`, `trade_order_item` |
| 20250621000000 | Added to `trade_order`: `paid_at`, `refunded_at`, `fulfilled_at`, `payment_method`, `tracking_number`, `shipping_address`, `refund_reason` |

## 11. Documentation Assets

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
| `docs/design/bundles/trade.md` | E-commerce module design (with new endpoints) |
| `docs/design/bundles/wallet.md` | Wallet module design |
| `docs/design/bundles/identity.md` | Auth module design |
| `docs/openapi/endpoints.yaml` | Standalone complete API reference (60+ endpoints, request bodies, examples) |
| `docs/ai/context.md` | This file — AI context snapshot |
| `mkdocs.yml` | MkDocs Material site config |
| `scripts/tests/simulate-trade.php` | Generates 100 orders across all 8 statuses into `var/test.db` |
| `scripts/tests/demo-trade-workflow.php` | E2E workflow demo (all transitions + guards) |
| `scripts/tests/demo-trade-workflow.sh` | curl-based demo script |
| `scripts/tests/test_exception_handler.php` | Exception handler test |

## 12. Testing

- **Framework**: PHPUnit 12.5
- **DB**: SQLite `var/test.db` in test environment
- **Coverage**: 80% minimum (enforced in CI)
- **Test count**: 795 tests, 2702 assertions
- **Key test groups**:
  - `tests/Trade/`: 171 tests (Entity, Service, Pricing, Integration, EventListener, Workflow API)
  - `tests/Wallet/`: 63 tests (Entity, Integration, Transfer Service, API regression)
  - `tests/Common/`: 68 tests (Entity, Integration, Batch update)
  - `tests/Identity/`: 11 tests (Auth, OTP, Token, Black box)
  - `tests/Integration/`: ~20 cross-module tests
  - `tests/Core/`: BaseService, RestController, Parser, Serializer, Utils

## 13. Environment Variables (Key)

| Var | Purpose |
|-----|---------|
| `APP_ENV`, `APP_DEBUG` | Symfony environment |
| `DATABASE_URL` | DB connection (PostgreSQL/SQLite/MySQL) |
| `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH` | RS256 key pair |
| `JWT_PASSPHRASE` | Private key passphrase |
| `JWT_REFRESH_TOKEN_SECRET` | HMAC-SHA256 secret |
| `OTP_REDIS_DSN` | Redis for OTP storage |
| `ALIYUN_SMS_*` | Alibaba Cloud SMS |
| `MESSENGER_TRANSPORT_DSN` | Async transport |
| `DEFAULT_URI` | Base URL for CLI contexts |
| `MAILER_DSN` | Mailer transport |

## 14. Console Commands

| Command | Module | Purpose |
|---------|--------|---------|
| `app:identity:user:create` | Identity | Create user: email, username, password, --phone, --role, --admin, --phone-verified |

## 15. Service Container Wiring

- Default: all `src/` classes autowired/autoconfigured
- Explicit exclusions: `FlatNormalizer`, EventListener classes (except `OpenApiEnricherListener`), Auth/Otp controllers, TokenManager, AliyunSmsProvider, RedisOtpStorage
- `OpenApiEnricherListener`: registered with `kernel.event_listener` tag on `kernel.response` (priority -10)
- `RestController` subclasses get `RequestStack`, `SerializerInterface`, `TranslatorInterface` via `#[Required]` setter injection
- `PriceCalculatorInterface` implementations auto-tagged `trade.price_calculator`, sorted by `getPriority()`
