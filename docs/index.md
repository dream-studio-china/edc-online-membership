# CRUD Skeleton

Symfony 8.1 API backend skeleton with modular architecture, JWT authentication, dynamic query engine, and pluggable business modules.

## Architecture

```
src/
├── Core/       # Framework core (RestController, BaseService, View Mixins, Expression Parser)
├── Common/     # CMS module (Category, Tag, Content, Comment, Page, Media, Setting)
├── Trade/      # E-Commerce (Product, Specification, Order, OrderItem, Pricing Pipeline)
├── Payment/    # Payments (Invoice, Gateway abstraction, Webhooks, Events)
├── Wallet/     # Wallet (Balance, Atomic Transfers, Idempotency)
├── Wechat/     # WeChat (Mini Program/Official Account Login, WeChat Pay V3, Gateway)
└── Identity/   # Authentication (JWT RS256, OTP SMS, Refresh Token Rotation)
```

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Symfony 8.1 |
| Language | PHP 8.4+ |
| ORM | Doctrine ORM 3.6 |
| Database | MySQL 8 |
| Auth | JWT (RS256) + OTP (SMS via Alibaba Cloud) |
| API Docs | Swagger UI (`/api/doc`) via NelmioApiDocBundle |
| Testing | PHPUnit 12.5 (90% coverage minimum) |
| Static analysis | PHPStan Level 8 + Rector type-rule dry-run |
| Frontend | [crud-admin](https://github.com/immane/crud-admin) — configuration-driven admin panel |

## Key Features

- **Expression-based dynamic queries**: `@filter`, `@sort`, `@dql`, `@order`, `@select` query parameters with DQL compilation
- **Trait-based controller composition**: 9 PHP traits (List, Detail, Create, Update, Delete, Workflow, etc.) assembled into controllers
- **Pluggable price calculation pipeline**: Priority-ordered calculators for e-commerce order pricing
- **State machine**: Symfony Workflow for order lifecycle (draft -> completed) and invoice lifecycle (pending -> paid/refunded)
- **Invoice-based payment framework**: Gateway abstraction (mock/wallet/wechat), webhooks, provider-agnostic invoice events
- **WeChat integration**: Mini Program and Official Account login, WeChat Pay V3 gateway, WechatUser entity (OneToOne→User)
- **System introspection**: Entity metadata and route export via `/system/*` endpoints
- **Atomic wallet transfers**: Deadlock prevention, optimistic locking, idempotency
- **Token rotation with reuse detection**: Refresh tokens hashed (HMAC-SHA256), rotated on use

## Quick Start

```bash
# Clone
git clone https://github.com/immane/crud-skeleton.git
cd crud-skeleton

# Start all services
docker compose up -d --build

# Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Create an admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

## Documentation

- **[Design Contracts](design/system-architecture.md)** -- System architecture rules, API design, data model, controller contract
- **[Bundles](design/bundles/core.md)** -- Per-module design documents (Core, Common, Trade, Wallet, Identity)
- **[API Docs](/api/doc)** -- Interactive Swagger UI (when running)

## Quality Checks

Use PHP 8.4 or newer, then run the checks enforced by CI:

```bash
composer phpstan
composer rector:types:check
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

The PHPStan and Rector jobs use isolated SQLite URLs so Composer's cache-clear
script can run without a locally configured development database.

## License

Apache-2.0
