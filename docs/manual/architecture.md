# Architecture

## 1. Service Topology

The CRUD Platform is a **multi-application monorepo** containing **8 independently
bootable Symfony applications** plus a root monolith that hosts them during
transition. Each service owns its Kernel, database, migrations, queues, worker,
scheduler, container image, and tests.

| # | Service | Namespace | Database | Status |
|---|---------|-----------|----------|--------|
| 1 | **Monolith (root)** | `App\*` (bridges) | `app` | Transition host; will be removed after Gateway cutover |
| 2 | **Common** | `App\Common\Main\*`, `App\Common\Storage\*` | `common` | Fully extracted |
| 3 | **Identity** | `App\Identity\Main\*`, `App\Identity\Wechat\*` | `identity` | Fully extracted |
| 4 | **Trade** | `App\Trade\Trade\*`, `App\Trade\Promotion\*` | `trade` | Fully extracted |
| 5 | **Store** | `App\Store\*` | `store` | Fully extracted — first extracted service |
| 6 | **Inventory** | `App\Inventory\*` | `inventory` | Extracted; production gated by safety checklist |
| 7 | **Payment** | `App\Payment\*` | `payment` | Fully extracted |
| 8 | **Wallet** | `App\Wallet\*` | `wallet` | Fully extracted |

### Shared Infrastructure

| Component | Location | Owned By |
|-----------|----------|----------|
| `platform-kernel` | `packages/platform-kernel/` | Framework (Core) — never a business service |
| `integration-contracts` | `packages/integration-contracts/` | Shared — transport-neutral v1 carrier classes |
| `legacy-messenger-compat` | `packages/legacy-messenger-compat/` | Shared — historical native-PHP Messenger wrapper FQCNs |
| Tests | root `tests/` | Root monolith — cross-module integration tests |

## 2. Layer Architecture

Every application follows a strict layered architecture:

```
Request/Response
      ↓
+-----v------------------------------------+
|  HTTP Layer  (Controllers / View Mixins) |  ← Only layer touching Request/Response
+-----+------------------------------------+
      ↓  (Service Interface only)
+-----v------------------------------------+
|  Service Layer                           |  ← All business logic, transactions, validation
+-----+------------------------------------+
      ↓  (Repository only)
+-----v------------------------------------+
|  Repository Layer                        |  ← Data access queries (Doctrine repositories)
+-----+------------------------------------+
      ↓  (Entities only)
+-----v------------------------------------+
|  Entity Layer (Domain Model)             |  ← Persistence and aggregate-local invariants
+-----+------------------------------------+
      ↓  (Doctrine ORM)
+-----v------------------------------------+
|  Infrastructure (ORM, Cache, Serializer) |  ← Framework-provided
+------------------------------------------+
```

### Layer Dependency Rules

| Rule | From | To | Allowed |
|------|------|----|---------|
| R1 | Controller | Service | **YES** |
| R2 | Controller | Entity | **YES** (type hints/returns only) |
| R3 | Controller | Repository | **NO** — go through Service |
| R4 | Controller | EntityManager | **NO** — go through Service |
| R5 | Service | Repository | **YES** |
| R6 | Service | Entity | **YES** |
| R7 | Service | EntityManager | **YES** |
| R8 | Service | Other Services | **YES** (via DI) |
| R9 | Entity | Repository | **NO** |
| R10 | Entity | Service | **NO** |
| R11 | Entity | EntityManager | **NO** |

Controllers receive `Request`/`Response` objects; Services never do. Services own
transaction boundaries via `BaseServiceInfrastructureTrait::wrapInTransaction()`.

## 3. Monorepo Layout

```
crud-platform/
├── apps/                      # Independently deployable business services
│   ├── common/                #   CMS + Storage
│   ├── identity/              #   Auth + WeChat login
│   ├── trade/                 #   Commerce + Promotion
│   ├── store/                 #   Store operations
│   ├── inventory/             #   Stock & reservation
│   ├── payment/               #   Invoices & gateways
│   └── wallet/                #   Balances & transactions
├── packages/                  # Reusable PHP libraries
│   ├── platform-kernel/       #   Framework core (App\Core)
│   ├── integration-contracts/ #   Transport-neutral event carriers
│   └── legacy-messenger-compat/ #  Historical wrapper FQCNs
├── src/                       # Root monolith (bridges only during transition)
│   ├── Kernel.php
│   └── Bridge/                #   Temporary adapters (e.g., PaymentWallet)
├── config/                    # Root monolith configuration
│   ├── services.yaml
│   ├── routes.yaml
│   └── packages/
├── migrations/                # Root monolith migration chain (historical)
├── translations/              # i18n YAML files (en, zh, zh_Hant, ja)
├── tests/                     # Root integration tests (963 tests)
├── docs/                      # All documentation
│   ├── manual/                #   Developer manuals (this collection)
│   ├── design/                #   Architecture contracts
│   ├── ai/                    #   AI context snapshots
│   └── openapi/               #   Frontend integration guides
├── scripts/                   # Utility scripts (tests, coverage, smoke)
├── compose.yaml               # Docker Compose (22 services)
├── compose.override.yaml      # Dev overrides
├── compose.prod.yaml          # Production overlay
└── Dockerfile                 # Root monolith FrankenPHP image
```

### `apps/` vs `packages/` Distinction

- **`apps/`**: A deployable business service with its own Kernel, database,
  migrations, tests, and Docker image. Owns business entities and logic.
- **`packages/`**: A reusable PHP library. Contains framework utilities, contracts,
  or compatibility shims. Must NOT contain business entities or service-specific
  application logic.

## 4. Extraction Status

All 8 bounded contexts have been extracted to `apps/`. The root monolith loads
each via Composer path packages (`crud-platform/{name}-app`) as a transition host.

| Context | Composer Package | Source Location | Entity De-prefixing |
|---------|-----------------|-----------------|---------------------|
| Store | `crud-platform/store-app` | `apps/store/src/` | `StoreMembership` → `Membership`, `StoreConsumedEvent` → `InboxMessage`, `StoreOutboxMessage` → `OutboxMessage`, `StoreTradeOrderCancellation` → `TradeOrderCancellation` |
| Inventory | `crud-platform/inventory-app` | `apps/inventory/src/` | N/A (created post-extraction) |
| Payment | `crud-platform/payment-app` | `apps/payment/src/` | N/A (created post-extraction) |
| Wallet | `crud-platform/wallet-app` | `apps/wallet/src/` | Legacy `user_id` FK removed; uses `ownerUuid` only |
| Identity | `crud-platform/identity-app` | `apps/identity/src/` | N/A |
| Common | `crud-platform/common-app` | `apps/common/src/` | N/A |
| Trade | `crud-platform/trade-app` | `apps/trade/src/` | N/A (owns Trade + Promotion) |

**Cutover status**: All services boot independently. The monolith remains the
production host. Root `src/` retains only `Bridge/` adapters; business source is
fully owned by `apps/`.

## 5. Event-Driven Integration Flow

The primary integration chain is **Trade → Store → Inventory**:

```
Trade order created (trade.order.created.v1)
  → Store Outbox/Inbox receives event
  → Store creates StoreOrder, validates
  → Store publishes inventory.reservation.requested.v1 (if INVENTORY_ENABLED)
  → Inventory receives, resolves recipes, reserves stock
  → Inventory publishes confirmed or rejected outcome
  → Store receives outcome, accepts or rejects StoreOrder
  → Store publishes store.order.accepted.v1 or store.order.rejected.v1
  → Trade receives, applies workflow transition
```

**Payment → Trade** uses the same pattern:
```
Payment invoice lifecycle change (payment.invoice.paid.v1, etc.)
  → Payment writes to PaymentOutbox
  → Trade Inbox receives, updates order
```

**Store → Trade (directory sync)**:
```
Store directory changes → StoreDirectoryOutboxListener → store.directory.upserted.v1
  → Trade maintains local trade_store_directory projection
```

All chains use the **Outbox/Inbox** pattern with idempotency by `eventId`.
Correlation IDs propagate through the chain for distributed tracing.

## 6. Key Design Patterns

| Pattern | Where | Detail |
|---------|-------|--------|
| **Outbox/Inbox** | Trade, Store, Inventory, Payment | Events written to Outbox in local transaction; relayed via scheduler; consumed idempotently via Inbox keyed by `eventId` |
| **UUID identity** | Trade, Wallet, Store | `UUID::v4()` for external aggregate identity; never expose auto-increment PKs across service boundaries |
| **Pricing pipeline** | Trade | `PriceCalculatorInterface` implementations sorted by priority: BasePriceCalculator(-100) → QuantityCalculator(50) → TotalAggregator(55) → PromotionCalculator(60) |
| **Gateway registry** | Payment | `PaymentGatewayInterface` implementations auto-tagged `payment.gateway`, collected via `#[AutowireIterator]` |
| **Snapshot** | Trade | `OrderItem` captures `specSnapshot`/`productSnapshot` at creation time |
| **Optimistic locking** | Wallet | `#[ORM\Version]` on `Wallet` entity for concurrent access safety |
| **Soft delete** | Trade | `isDeleted` boolean on `Product`, `Specification` |
| **commonFilter** | Controllers | Array criteria or QueryBuilder scoping: `[]` = admin, `['user' => $user]` = user-scoped, `['id' => -1]` = block all |
| **Token rotation** | Identity | HMAC-SHA256 refresh tokens with reuse detection |
| **Correlation tracing** | Integration events | `correlationId` propagates through the Trade→Store→Inventory chain; `causationId` is the immediate parent `eventId` |
| **Adjustment provider registry** | Payment | `PaymentAdjustmentProviderInterface` implementations auto-tagged; Wallet provides `WalletBalanceAdjustmentProvider` |
| **Storage driver registry** | Storage | `MediaStorageInterface` implementations tagged `media.storage`; selectable via multipart `storage` field |
| **Promotion strategy tag** | Promotion | `promotion.strategy` auto-tag, collected via `#[AutowireIterator]` (7 strategies) |
| **Balance audit** | Wallet | `GET /app/wallets/balance` (user-scoped) and `GET /manage/wallets/balance` (global) with reconciliation |
| **Expression query engine** | Core | `@filter`, `@dql`, `@order`, `@select`, `@groupBy`, `@sort`, `@expands`, `@display`, `@transform` parameters |

## 7. Cross-Service Dependency Rules

These rules govern how services communicate during transition:

1. **No cross-service Doctrine associations** — A service owns its database and
   has no FK, repository access, or transaction spanning another service.
2. **Durable references use UUIDs** — Never reference another service's local
   integer primary key.
3. **Scalar contracts only** — Service boundaries accept scalar commands/queries
   and emit scalar snapshots. No Doctrine entities, repositories, or Symfony
   requests pass boundaries.
4. **Outbox for writes** — Every state-changing integration event is written to
   an Outbox in the producer's local transaction.
5. **Inbox for reads** — Consumers use a durable Inbox keyed by `eventId`.
6. **Owned queues** — Each consumer owns its queue, retry policy, dead-letter
   handling, worker, and scheduled jobs.
7. **Shared packages are utilities only** — No business entities in `packages/`.

Current boundary risks (to be resolved before full independence):
- Synchronous `InvoiceService` → Trade order updates (being replaced by Outbox/Inbox)
- Payment/Wechat plugin contracts exposing Doctrine/Symfony types
- Legacy native-PHP Messenger wrapper FQCNs in historical queue records
- Root `Bridge/` adapters coupling Payment and Wallet
