# CRUD Platform

CRUD Platform is the evolution of [crud-skeleton](https://github.com/immane/crud-skeleton): A production-oriented Symfony microservices platform evolved from modular monolith architecture, featuring DDD, service isolation, and event-driven communication.

> Chinese (Simplified): [README.zh-cn.md](README.zh-cn.md) · Chinese (Traditional): [README.zh-hant.md](README.zh-hant.md) · Japanese: [README.ja.md](README.ja.md)

---

## Project Goal

The target is a **monorepo with independently deployable Symfony services**.
Each service will own its Kernel, configuration, database and migrations, queues,
workers, scheduled jobs, Docker image, tests, and CI.

The repository is currently a **modular monolith**, not a completed microservice
system. It has one Kernel, Composer project, container, database, migration chain,
Messenger queue, worker, scheduler, and Docker image. The `Trade → Store →
Inventory` Outbox/Inbox flow is the first extraction seam.

The transition rules, target directory structure, and extraction gates are in
[Microservice Transition Contract](docs/design/microservice-transition.md).

---

## Architecture

### Service Topology

```
                    ┌──────────────────────────────────────────────┐
                    │              API Gateway / Edge              │
                    └──────┬──────┬──────┬───────────┬────────┬────┘
                           │      │      │           │        │ 
    ┌──────────┐  ┌────────┴─┐ ┌──┴───┐ ┌┴──────┐ ┌──┴───┐ ┌──┴────┐
    │ Identity │  │ Commerce │ │Store │ │Media  │ │Wallet│ │Payment│
    │  :8085   │  │  :8087   │ │:8081 │ │:8086  │ │:8084 │ │:8083  │
    └────┬─────┘  └────┬─────┘ └──┬───┘ └──┬────┘ └──┬───┘ └───┬───┘
         │             │          │        │         │         │
    ┌────┴────┐   ┌────┴──────┐ ┌─┴───┐ ┌──┴───┐ ┌───┴───┐ ┌───┴───┐
    │   DB    │   │    DB     │ │ DB  │ │  DB  │ │  DB   │ │  DB   │
    │identity │   │  trade    │ │store│ │common│ │wallet │ │payment│
    └─────────┘   └───────────┘ └─────┘ └──────┘ └───────┘ └───────┘

    ┌──────────┐
    │Inventory │  + Root Monolith (app :8080) — transition host
    │  :8082   │  + Worker + Scheduler (shared)
    └────┬─────┘  + Redis + Mailpit
    ┌────┴────┐
    │   DB    │
    │inventory│
    └─────────┘
```

### Event-Driven Integration (Outbox / Inbox)

```
  Trade                    Store                   Inventory
  ┌──────────┐ outbox      ┌──────────┐ outbox     ┌──────────┐
  │ order    │───order────→│store     │──reserve──→│material  │
  │ created  │  created.v1 │ order    │ request.v1 │ reserve  │
  │          │←──accept────│ accepted │←─confirm───│ confirm  │
  │          │  accepted   │          │ confirmed  │          │
  └──────────┘             └──────────┘            └──────────┘
       │                        │                       │
       └── store.directory.upserted.v1 ──→ local projection
```

### Layer Architecture (Per Service)

```
  HTTP Controller  ←  only layer touching Request/Response
        │
  Service Layer    ←  all business logic, transactions, validation
        │
  Repository       ←  data access (Doctrine)
        │
  Entity / Domain  ←  persistence and aggregate invariants
        │
  Infrastructure   ←  ORM, Cache, Serializer (framework-provided)
```

### Repository Layout

```
├── apps/                         # Independently deployable services
│   ├── identity/                 # App\Identity — auth, JWT, OTP, WeChat login
│   │   ├── src/Main/             #   accounts, profiles, refresh tokens
│   │   └── src/Wechat/           #   WeChat Mini Program / OAuth adapters
│   ├── common/                   # App\Common — CMS, media, categories, tags
│   │   ├── src/Main/             #   content entities and CRUD
│   │   └── src/Storage/          #   pluggable file upload (Local, Qiniu)
│   ├── trade/                    # App\Trade — orders, products, pricing
│   │   ├── src/Trade/            #   order workflow, outbox, message handlers
│   │   └── src/Promotion/        #   DSL-driven promotion engine, 7 strategies
│   ├── store/                    # App\Store — multi-store operations
│   ├── inventory/                # App\Inventory — stock reservation, recipes
│   ├── payment/                  # App\Payment — invoices, gateways, adjustments
│   ├── wallet/                   # App\Wallet — ledger, transfers, deductions
├── packages/
│   ├── platform-kernel/          # App\Core framework (RestController, DQL, utils)
│   ├── integration-contracts/    # Versioned neutral event carriers
│   └── legacy-messenger-compat/  # Historical messenger wrapper FQCNs
├── src/                          # Root monolith (transition host only)
│   ├── Bridge/                   #   Composition adapters (root → service ports)
│   └── Kernel.php                #   Root Kernel
├── config/                       # Root service wiring, routes, Doctrine mappings
├── docs/                         # Design contracts, AI context
└── scripts/                      # Smoke tests, coverage tooling, trade demos
```

### Extraction Status

| Target context | Moved to | Status |
|---|---|---|
| Platform Kernel | `packages/platform-kernel` | Shared framework library |
| Commerce | `apps/trade` (Trade + Promotion) | Extracted; Payment direct dependency remains |
| Store Operations | `apps/store` | Extracted |
| Inventory | `apps/inventory` | Extracted; production-gated |
| Payments | `apps/payment` (gateways, adjustments) | Extracted; gateways owned by Payment app |
| Wallet/Ledger | `apps/wallet` | Extracted; `ownerUuid` only |
| Identity & Access | `apps/identity` (Main + Wechat login) | Extracted |
| Content/Media | `apps/common` (CMS + Storage) | Extracted |

---

## Current Capabilities

- **Framework**: Symfony 8.1, PHP 8.4+, Doctrine ORM 3.6, MySQL 8, SQLite tests.
- **Identity & Access**: RS256 JWT, refresh-token rotation, OTP/SMS (Aliyun),
  password login, WeChat Mini Program / Official Account OAuth login.
- **Commerce**: Product catalog, order state machine (draft→completed→refunded),
  store-aware pricing pipeline (base→quantity→subtotal→promotion), multi-store
  acceptance/rejection workflow.
- **Promotion engine**: Custom DSL lexer/parser/evaluator with 7 strategy types
  (discount, gift, tiered, full-reduction, free-shipping, member, Nth-item).
- **Store Operations**: Multi-store directory, store-scoped orders, membership,
  staff order management.
- **Inventory**: Material master, specification recipes, atomic stock reservation
  with per-store `allowNegativeStock` policy (disabled by default, not
  production-ready).
- **Payments**: Invoice lifecycle (pending→paid→refunded), multi-gateway registry
  (mock, wallet, WeChat Pay V3), pre-payment adjustment pipeline.
- **Wallet**: Double-entry ledger, transfers, payment deductions, balance audit,
  optimistic locking, idempotent deposits.
- **CMS & Media**: Categories, tags, content, comments, pages, settings, media
  upload with pluggable storage drivers (Local, Qiniu).
- **Integration**: Versioned Trade/Store/Inventory/Payment events, Outbox/Inbox
  idempotency, correlation/causation trace propagation, 10 neutral carriers.
- **i18n**: Symfony Translation — English, Simplified Chinese, Traditional
  Chinese, Japanese (~280 keys per locale).
- **API Doc**: NelmioApiDoc with Swagger UI at `/api/doc`, auto-tagged endpoints,
  44+ named schemas.

---

## Quick Start

Docker is the supported local path. From the repository root, Docker is the only
required prerequisite:

```bash
docker compose up -d --build

# Root monolith
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Extracted apps
for svc in store-app inventory-app payment-app wallet-app identity-app common-app trade-app; do
  docker compose exec $svc php bin/console doctrine:migrations:migrate --no-interaction
done

# Create admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# Verify
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

| Service | Port | Database | Status |
|---|---|---|---|
| Root monolith (app) | `8080` | `database` | Transition host |
| Store | `8081` | `store-database` | Extracted |
| Inventory | `8082` | `inventory-database` | Extracted, gated |
| Payment | `8083` | `payment-database` | Extracted |
| Wallet | `8084` | `wallet-database` | Extracted |
| Identity | `8085` | `identity-database` | Extracted |
| Common | `8086` | `common-database` | Extracted |
| Trade | `8087` | `trade-database` | Extracted |

- OpenAPI UI: `http://localhost:8080/api/doc`
- Mailpit (email testing): `http://localhost:8025`
- Worker/scheduler logs: `docker compose logs -f worker scheduler`

For troubleshooting and native-PHP notes, see [QUICKSTART.md](QUICKSTART.md).

---

## Development

```bash
# Test suites
./vendor/bin/phpunit                           # Root integration + remaining unit tests
composer coverage                              # All 8 suites + aggregate gate (>= 90%)

# Static analysis
composer phpstan                                # PHPStan Level 8
composer deptrac                                # Architecture boundaries
composer rector:types:check                     # Rector type-rule dry-run

# Docs
mkdocs build --strict
```

**Test structure**: Seven standalone app test suites (common, identity, inventory,
payment, store, trade, wallet) run independently alongside root integration tests
(963 tests). Aggregate line coverage is **91.36%** (1,785 tests, 6,098 assertions)
enforced via a phpcov merge gate in CI.

Use PHP 8.4+ for local commands.

---

## Integration Contracts

Ten versioned neutral carriers connect services:

| Type | Carrier | Direction |
|---|---|---|
| Event | `trade.order.created.v1` | Trade → Store |
| Event | `trade.order.cancelled.v1` | Trade → Store |
| Event | `store.order.accepted.v1` | Store → Trade |
| Event | `store.order.rejected.v1` | Store → Trade |
| Event | `store.directory.upserted.v1` | Store → Trade (projection) |
| Command | `inventory.reservation.requested.v1` | Store → Inventory |
| Command | `inventory.reservation.release.requested.v1` | Store → Inventory |
| Event | `inventory.reservation.confirmed.v1` | Inventory → Store |
| Event | `inventory.reservation.rejected.v1` | Inventory → Store |
| Event | `inventory.reservation.released.v1` | Inventory → Store |
| Event | `payment.invoice.{paid,failed,cancelled,refunded}.v1` | Payment → Trade (in-progress) |

Each envelope carries `eventId`, `type`, `version`, `aggregateType`, `aggregateId`,
`occurredAt`, `correlationId`, `causationId`, and `payload`. Publishers atomically
write to Outbox in the same transaction; consumers use Inbox idempotency by
`eventId`.

---

## Key Patterns

| Pattern | Where | Detail |
|---|---|---|
| **Outbox/Inbox** | Trade, Store, Inventory, Payment | Durable event relay with idempotency |
| **Correlation tracing** | All Outboxes | `correlationId`/`causationId` propagation across services |
| **UUID identity** | Trade, Wallet | `UUID::v4()` for external identity references |
| **Money in cents** | Wallet, Trade, Payment | `bigint` cents, API boundary ×/÷100 |
| **State machine** | Trade | Symfony Workflow for order lifecycle |
| **Pricing pipeline** | Trade | Tagged `PriceCalculatorInterface` with priority ordering |
| **Gateway registry** | Payment | `#[AutowireIterator]` for pluggable payment gateways |
| **Adjustment pipeline** | Payment | Pre-payment deduction hooks before gateway execution |
| **Optimistic locking** | Wallet | `#[ORM\Version]` on Wallet entity |
| **Snapshot** | Trade | `OrderItem` captures `specSnapshot`/`productSnapshot` |
| **Soft delete** | Trade | `isDeleted` on Product, Specification |
| **commonFilter** | Controllers | User-scoped or admin-scoped QueryBuilder injection |
| **Promotion DSL** | Promotion | Custom lexer/parser/evaluator for business rules |
| **DRY-run backfills** | Trade, Store, Inventory | Bounded/resumable correlation backfill commands |
| **Token rotation** | Identity | HMAC-SHA256 refresh tokens with reuse detection |

---

## Console Commands

| Command | Service | Purpose |
|---|---|---|
| `app:identity:user:create` | Identity | Create user with roles |
| `app:trade:outbox:publish` | Trade | Relay unpublished integration events |
| `app:store:outbox:publish` | Store | Relay acceptance/rejection events |
| `app:inventory:outbox:publish` | Inventory | Relay reservation outcomes |
| `app:payment:outbox:publish` | Payment | Relay invoice lifecycle events |
| `app:trade:outbox:backfill-correlation` | Trade | Bounded correlation backfill (dry-run / --apply) |
| `app:store:outbox:backfill-correlation` | Store | Bounded correlation backfill (dry-run / --apply) |
| `app:inventory:outbox:backfill-correlation` | Inventory | Bounded correlation backfill (dry-run / --apply) |
| `app:payment:outbox:backfill-correlation` | Payment | Bounded correlation backfill (dry-run / --apply) |
| `app:store:outbox:backfill-directory` | Store | Backfill Store directory events |
| `app:inventory:reservations:release-expired` | Inventory | Release expired reservations |
| `app:storage:qiniu:settings:init` | Common | Initialize Qiniu settings |

---

## Docker Compose Topology

**22 services** in `compose.yaml`:

| Group | Services |
|---|---|
| Root | `app` (FrankenPHP), `worker` (Messenger async), `scheduler` (outbox relay) |
| Apps | `store-app`, `inventory-app`, `payment-app`, `wallet-app`, `identity-app`, `common-app`, `trade-app` |
| Databases | `database` (root), `store-database`, `inventory-database`, `payment-database`, `wallet-database`, `identity-database`, `common-database`, `trade-database` |
| Infra | `redis` (OTP/cache), `mailer` (Mailpit) |

The worker consumes the shared `async` transport. The scheduler polls outbox
publication for Trade, Store, Inventory, and Payment, plus inventory expiry release.

---

## Developer Onboarding

New to the project? Read the [Development Manual](docs/manual/index.md) in this order:

1. [Architecture](docs/manual/architecture.md) — understand the service topology, layers, and patterns
2. [Getting Started](docs/manual/getting-started.md) — set up Docker and verify it works
3. [Project Structure](docs/manual/project-structure.md) — learn where everything lives
4. [Core Framework](docs/manual/core-framework.md) — the framework powering every app: `RestController`, `BaseService`, View mixins, EventListeners, Utils
5. [Core Usage](docs/manual/core-usage.md) — hands-on recipes: build a controller, service, file upload, custom action
6. [Query System](docs/manual/query-system.md) — master `@filter`, `@sort`, `@order`, `@dql`, `@select`, `@groupBy`, `@expands`, `@display`, `@transform`
7. [API Contracts](docs/manual/api-contracts.md) — request/response envelope, auth, pagination, error handling, API docs
8. [Development Contracts](docs/manual/development-contracts.md) — coding rules, layer boundaries, naming, security

Then explore the remaining guides as needed: [Testing](docs/manual/testing.md),
[Integration Events](docs/manual/integration-events.md),
[Database & Migrations](docs/manual/database-and-migrations.md),
[Deployment](docs/manual/deployment.md),
[Extracting a Service](docs/manual/extracting-a-service.md),
[Internationalization](docs/manual/i18n.md).

## Documentation

- [Development Manual](docs/manual/index.md) — comprehensive developer guide (16 documents)
- [Microservice Transition Contract](docs/design/microservice-transition.md)
- [System Architecture](docs/design/system-architecture.md)
- [System Contracts](docs/design/system-contracts.md)
- [Module Design](docs/design/module-design.md)
- [AI Context](docs/ai/context.md)
- [Documentation site](https://immane.github.io/crud-skeleton)

---

## License

Apache-2.0. See [LICENSE](LICENSE).
