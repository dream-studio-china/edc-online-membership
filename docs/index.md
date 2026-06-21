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
└── Identity/   # Authentication (JWT RS256, OTP SMS, Refresh Token Rotation)
```

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Symfony 8.1 |
| Language | PHP 8.4+ |
| ORM | Doctrine ORM 3.6 |
| Database | PostgreSQL 16 |
| Auth | JWT (RS256) + OTP (SMS via Alibaba Cloud) |
| API Docs | Swagger UI (`/api/doc`) via NelmioApiDocBundle |
| Testing | PHPUnit 12.5 (80% coverage minimum) |
| Assets | Stimulus + Turbo via AssetMapper |

## Key Features

- **Expression-based dynamic queries**: `@filter`, `@sort`, `@dql`, `@order`, `@select` query parameters with DQL compilation
- **Trait-based controller composition**: 9 PHP traits (List, Detail, Create, Update, Delete, Workflow, etc.) assembled into controllers
- **Pluggable price calculation pipeline**: Priority-ordered calculators for e-commerce order pricing
- **State machine**: Symfony Workflow for order lifecycle (draft -> completed) and invoice lifecycle (pending -> paid/refunded)
- **Invoice-based payment framework**: Gateway abstraction (mock/wallet), webhooks, provider-agnostic invoice events
- **Atomic wallet transfers**: Deadlock prevention, optimistic locking, idempotency
- **Token rotation with reuse detection**: Refresh tokens hashed (HMAC-SHA256), rotated on use

## Quick Start

```bash
# Clone
git clone https://github.com/immane/crud-skeleton.git
cd crud-skeleton

# Install dependencies
composer install

# Start services
docker compose up -d

# Run migrations
php bin/console doctrine:migrations:migrate

# Start dev server
php -S localhost:8000 -t public/
```

## Documentation

- **[Design Contracts](design/system-architecture.md)** -- System architecture rules, API design, data model, controller contract
- **[Bundles](design/bundles/core.md)** -- Per-module design documents (Core, Common, Trade, Wallet, Identity)
- **[API Docs](/api/doc)** -- Interactive Swagger UI (when running)

## License

MIT
