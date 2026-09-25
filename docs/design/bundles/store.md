# Store Bundle Design

> **Status: phase 1-3 implemented in the modular monolith.** Store, membership,
> StoreOrder, Trade/Store Outbox-Inbox, and Messenger consumers are implemented.
> Trade workflow is simplified (`draft -> pending -> confirmed -> paid -> fulfilled -> completed`);
> StoreOrder is a projection created from `trade.order.created.v1` and auto-accepted when
> `INVENTORY_ENABLED=0`. Inventory reservation remains an intentionally deferred boundary.
> The Store bundle (`src/Store/`) owns multi-store context, store operations, the
> store-side order projection, and the Product/Specification catalog (shared/global
> `Product.store = NULL` and store-private `Product.store = Store`, tables
> `trade_product`/`trade_specification` retained). Trade remains the single commercial
> order entry point and the source of truth for order amount, payment, refund, and
> customer order status. See [Store Catalog Model](../store-catalog.md) for invariants.

---

## 1. Goals And Scope

### 1.1 Goal

Provide a multi-store boundary that can begin as a Symfony module and later be
extracted as a service without changing the ownership of commercial orders.

```text
Customer -> Trade Order API -> Trade Order + Trade Outbox
                                  |
                                  v
                         Store event consumer
                                  |
                                  v
                         StoreOrder + store decision
                                  |
                                  v
                         Store result event -> Trade consumer
```

The bundle owns:

- Store identity, lifecycle, business configuration, and public store identity.
- Store membership and store-local operational authorization.
- Store request context resolution and validation.
- The `StoreOrder` projection/aggregate for store acceptance and fulfillment data.
- Idempotent consumption and publication of Store integration events.
- The contract by which a store fulfills and verifies a Trade order (acceptance removed; fulfillment + verification only).
- Product and Specification catalog (shared/global and store-private, nullable `store`).

The bundle does not own:

- Customer order creation API.
- Commercial order amount, discounts, payment, refund, or customer cancellation.
- The canonical payment invoice.
- Inventory implementation. Inventory reservation is an integration boundary defined
  here and designed in a later Inventory bundle.

### 1.2 Non-Goals

The first Store phase MUST NOT:

- Create a second customer-facing order through `/stores/{id}/orders`.
- Maintain a writable copy of Trade payment, refund, or total amount state.
- Add Doctrine foreign keys to Trade, Payment, Identity, or future Inventory tables.
- Let a client-provided `storeId` or `storeCode` become an authorization fact.
- Treat `trade_order.metadata` as the authoritative store relation.
- Require distributed transactions or exactly-once broker delivery.

### 1.3 Ownership Matrix

| Concern | Owner | Store Responsibility |
|---|---|---|
| Order UUID, line-item amount, total, currency | Trade | Consume immutable snapshot only |
| Customer payment and refund | Trade + Payment | React to resulting business events only |
| Store identity and membership | Store | Authoritative |
| Store acceptance (historical, now auto-accept) | Store | Projection only; auto-accept when `INVENTORY_ENABLED=0` |
| Store fulfillment operations | Store | Authoritative once introduced |
| Store-specific item eligibility | Store | Validate during acceptance |
| Inventory reservation | Inventory, future | Request and react; do not own stock ledger |
| Promotion calculation | Promotion + Trade | Receive a server-resolved store code at quote/order time |

---

## 2. Architectural Principles

### 2.1 One Commercial Order

`Trade\Entity\Order` is the only customer order aggregate. A client creates it through
the existing Trade order endpoint. `StoreOrder` is created only as the result of a
`trade.order.created.v1` event.

```text
Trade Order                         StoreOrder
-----------                         ----------
payment / refund                    store acceptance
commercial workflow                 operational workflow
customer-facing status              store-facing status
commercial amount                   copied amount snapshot for display only
invoice references                  fulfillment and reservation references
```

`StoreOrder` MUST NOT expose an API that creates a standalone commercial order.

### 2.2 Explicit Event Contracts

Trade and Store communicate through versioned, serializable integration events. They
MUST NOT communicate by passing Doctrine entities, repositories, or service objects.

The publisher does not know which consumers exist. A consumer knows only an event
schema and its own local services.

### 2.3 Local Transaction, Eventual Cross-Bundle Consistency

Every module writes its own business records and its own outbox event in one database
transaction. A relay delivers events at least once. Consumers provide idempotency.

```text
local database transaction:
  business mutation + outbox insert

cross-module delivery:
  at least once + inbox deduplication + business unique keys
```

No component may assume an exactly-once message broker guarantee.

### 2.4 Runtime Workers

The modular monolith starts asynchronous processing as Compose services, not inside the
PHP-FPM request process:

| Service | Command | Responsibility |
|---|---|---|
| `worker` | `messenger:consume async --time-limit=3600 --memory-limit=256M --no-interaction` | Consume Trade, Store, and Inventory integration messages with Messenger retry handling |
| `scheduler` | Outbox publisher loop (`app:trade:outbox:publish`, `app:store:outbox:publish`, `app:inventory:outbox:publish`, `app:inventory:reservations:release-expired`, `app:settlement:allocations:requeue-due`, `app:settlement:outbox:publish`) | Relay unpublished Outbox rows every `OUTBOX_PUBLISH_INTERVAL` seconds (default `5`; `sleep "${OUTBOX_PUBLISH_INTERVAL:-5}"`) |

The scheduler and worker use the same application image and environment as `app`. This
keeps the Outbox pattern durable in SQL while making the monolith operationally automatic.

### 2.5 Stable Scalar References

Cross-boundary references are UUIDs and scalar snapshots, never Doctrine associations.

Examples:

- `StoreOrder.tradeOrderUuid`, not `ManyToOne Trade\Order`.
- `Membership.userUuid`, not `ManyToOne Identity\User`.
- Future inventory reservation UUID, not an Inventory entity relation.

This prevents cross-module schema coupling and permits separate databases later.

---

## 3. Existing System Changes Required

This bundle cannot be implemented as a completely isolated source directory because
Trade owns the only order entry point. The required changes are intentionally narrow
and generic; Trade MUST NOT import Store entities, repositories, or services.

| Area | Required target change | Reason |
|---|---|---|
| Trade order workflow | Simplified: `draft -> pending -> confirmed -> paid -> fulfilled -> completed` (no Store acceptance states) | Store is a projection; payment is not gated on Store acceptance |
| Trade order creation | Persist server-generated Store snapshot (`_store`, `_completionMode`) and always emit `trade.order.created.v1` when a `StoreContext` exists | Start asynchronous Store projection; `TradeOrder` always enters `pending` |
| Trade payment entry | No Store gate; standard Trade payment eligibility applies | Store no longer blocks payment |
| Trade completion guard | Enforce `fulfilled -> completed` only via `OrderCompletionGuardListener` / `OrderVerificationCompletionListener` when `_completionMode=store_verification` | Immutable verification requirement per order |
| Trade outbox relay | Publish versioned integration events | Decouple publisher from Store consumer |
| Identity User identity | Add a unique User UUID before Store persists or publishes user references | Prevent Store from depending on `users.id` |
| Root routing/DI | Register Store controllers and Store service configuration | Compose the new bundle |

The implemented Trade workflow is `draft -> pending -> confirmed -> paid -> fulfilled -> completed` for both store and non-store orders. There are no `awaiting_store_acceptance`, `store_accepted`, or `store_rejected` places. `Trade/Service/OrderService::createOrder` always applies `submit` (`draft -> pending`) when a `StoreContext` exists and always writes a `trade.order.created.v1` outbox row (see §6.3).

The current context transport is `X-Store-Code`, resolved only against an active Store
by `StoreContextResolver`. A Store-scoped App order creates a `pending` Trade order (HTTP `201`) and the Store consumer auto-accepts eligible active Stores when `INVENTORY_ENABLED=0`; inventory reservation is the future decision boundary. When the Store is unavailable (not found / not active) the consumer throws `RuntimeException` to trigger Messenger retry/DLQ.

### 3.1 Store Context At The Trade Entry Point

The Trade controller obtains a `StoreContext` before calculating a quote or creating
an order. It MUST use a generic interface or DTO, never a Store entity type.

```php
// App\Trade\DTO\StoreContext (Trade-owned DTO; Store never exposes entities)
final readonly class StoreContext
{
    public function __construct(
        public string $storeUuid,
        public string $storeCode,
        public string $storeName,
        public string $channel = 'api',
        public string $currency = 'CNY',
        public bool $requireVerification = false,
    ) {}

    /** @return array{uuid: string, code: string, name: string, channel: string, currency: string, requireVerification: bool} */
    public function toSnapshot(): array
    {
        return [
            'uuid' => $this->storeUuid,
            'code' => $this->storeCode,
            'name' => $this->storeName,
            'channel' => $this->channel,
            'currency' => $this->currency,
            'requireVerification' => $this->requireVerification,
        ];
    }
}
```

Resolved by `App\Store\Service\StoreContextResolver` (implements
`App\Trade\Service\StoreContextResolverInterface`, wired in `config/services.yaml`)
from the `X-Store-Code` header against an active Store only (`NotFoundHttpException`
when unknown/inactive; `null` when the header is absent), plus
`X-Store-Channel` (default `api`), `Store.currency`, and
`StoreSettings::from($store->getSettings())->requireVerification`. Trade quote/order
creation uses the resolved Store currency and rejects a mismatching requested currency.

The Store bundle implements the resolver. Trade consumes only the resolved scalar data.
The exact location mechanism may be one of:

| Mechanism | Use | Trust Rule |
|---|---|---|
| Canonical host/subdomain | Store-branded storefront | Resolve from trusted request host mapping |
| Gateway-injected header | API gateway or mini-program gateway | Accept only after gateway authentication/signature |
| Explicit route selection | A customer deliberately selects a store | Validate availability server-side before use |

Raw request-body `storeId`, `storeUuid`, and `storeCode` are never authoritative. A
client may provide a selection hint only when a Store resolver validates it and returns
a concrete `StoreContext`.

### 3.2 Trade Metadata Snapshot

Trade stores a historical display snapshot under a reserved metadata key:

```json
{
  "_store": {
    "uuid": "store-uuid",
    "code": "shanghai-xuhui",
    "name": "Xuhui Store",
    "channel": "mini_program",
    "currency": "CNY",
    "requireVerification": false
  },
  "_completionMode": "manual",
  "_storeVerificationReceived": true
}
```

- `_store.requireVerification` — snapshot of `Store.settings.fulfillment.requireVerification` at order creation time (immutable per order). Also carried in `trade.order.created.v1` `store.requireVerification` and persisted as `StoreOrder.verificationRequired` (see §5.3). Live `Store.settings` changes do **not** affect inflight orders.
- `_completionMode` — `manual` (default) or `store_verification`. Snapshotted by `Trade/Service/OrderService` as `storeContext->requireVerification ? 'store_verification' : 'manual'`. Enforced by `Trade/EventListener/OrderCompletionGuardListener` (blocks `complete` unless `allowCompletionFromStoreVerification`) and `Trade/EventListener/OrderVerificationCompletionListener` / `Trade/MessageHandler/StoreOrderVerifiedHandler`.
- `_storeVerificationReceived` — optional boolean flag set by `StoreOrderVerifiedHandler` when `store.order.verified.v1` arrives. If the Trade order is not yet `fulfilled`, the flag is stored and `OrderVerificationCompletionListener` auto-completes after `fulfill` (out-of-order handling).

The snapshot is written by trusted server code only. It is useful for audit and order
display, but it is not a query, authorization, or integrity boundary. `StoreOrder` is
the Store-side authoritative record, and the integration event carries the same
immutable snapshot. `TradeOrder.metadata` is the authoritative completion-mode source; `StoreOrder` stores the Store-side `verificationRequired` snapshot.

---

## 4. Module Structure

```text
src/Store/
|-- Command/
|   `-- PublishOutboxCommand.php              # app:store:outbox:publish
|-- Controller/
|   |-- App/
|   |   |-- StoreController.php              # Discover/read stores (ROLE_USER)
|   |   |-- StoreOrderController.php         # Customer read-only store order view (ROLE_USER)
|   |   |-- MembershipController.php         # Self-service join/status, fixed role=clerk (ROLE_USER)
|   |   |-- ProductController.php            # Shared/private catalog reads (DqlExpression row-scope)
|   |   `-- SpecificationController.php      # Specification reads + by-product (DqlExpression row-scope)
|   |-- Manage/
|   |   |-- StoreController.php              # Platform admin store CRUD + status + members grant/list (ROLE_ADMIN)
|   |   |-- StoreOrderController.php         # Cross-store operational reporting (ROLE_ADMIN)
|   |   |-- ProductController.php            # Catalog CRUD, accepts store UUID|null (ROLE_ADMIN)
|   |   |-- SpecificationController.php      # Spec CRUD under /manage/products/{productId} (ROLE_ADMIN)
|   |   `-- SpecificationAllController.php   # Spec CRUD under /manage/specifications (ROLE_ADMIN)
|   `-- Staff/
|       |-- StoreController.php              # Store profile view/update (owner/manager)
|       |-- StoreOrderController.php         # Scoped work queue + fulfill/verify (ROLE_USER + store:* voter)
|       |-- MembershipController.php         # Non-revoked member list with user display info (owner/manager)
|       |-- AssignmentController.php         # Store-scoped role assignments + assignable-roles (owner/manager)
|       |-- ProductController.php            # Store-private product CRUD, store fixed by URL (ROLE_USER + store:* voter)
|       `-- SpecificationController.php      # Spec CRUD under store product (ROLE_USER + store:* voter)
|-- DTO/
|   `-- StoreSettings.php                   # requireVerification value object (fulfillment.requireVerification)
|-- Entity/
|   |-- Store.php
|   |-- Membership.php
|   |-- StoreOrder.php
|   |-- Product.php                         # Nullable store (NULL=global), table trade_product
|   |-- Specification.php                   # Inherits visibility via Product, table trade_specification
|   |-- StoreOutboxMessage.php
|   |-- StoreConsumedEvent.php
|   `-- StoreTradeOrderCancellation.php     # Tombstone for cancel-before-projection races
|-- MessageHandler/
|   |-- TradeOrderCreatedHandler.php        # trade.order.created.v1 -> StoreOrder (auto-accept)
|   |-- TradeOrderCancelledHandler.php      # trade.order.cancelled.v1 -> cancel + tombstone + release
|   |-- ReservationConfirmedHandler.php     # inventory.reservation.confirmed.v1 -> accept
|   |-- ReservationRejectedHandler.php      # inventory.reservation.rejected.v1 -> reject
|   `-- ReservationReleasedHandler.php      # inventory.reservation.released.v1 -> inbox dedup only
|-- Repository/
|   |-- StoreRepository.php
|   |-- MembershipRepository.php
|   |-- StoreOrderRepository.php
|   |-- ProductRepository.php
|   |-- SpecificationRepository.php
|   |-- StoreOutboxMessageRepository.php
|   |-- StoreConsumedEventRepository.php
|   `-- StoreTradeOrderCancellationRepository.php
|-- Security/
|   `-- StoreAuthorizationVoter.php         # store:* on Store: MembershipService.isAuthorized + AuthorizationService.can(store scope)
|-- Service/
|   |-- StoreService.php / StoreServiceInterface.php
|   |-- StoreContextResolver.php            # Implements Trade StoreContextResolverInterface (X-Store-Code)
|   |-- MembershipService.php / MembershipServiceInterface.php  # grant/isAuthorized/requireAuthorization
|   |-- StoreOrderService.php / StoreOrderServiceInterface.php  # accept/reject/fulfill/verify/createFromTradeOrderSnapshot
|   |-- StoreOutboxService.php / StoreOutboxServiceInterface.php
|   |-- ProductService.php / ProductServiceInterface.php
|   |-- SpecificationService.php / SpecificationServiceInterface.php
|   |-- StoreSettingsResolver.php           # Store -> StoreSettings DTO
|   `-- Catalog/
|       `-- StoreCatalogResolver.php        # Implements Trade CatalogResolverInterface (storeCode visibility)
|-- View/
|   |-- StoreScopedAuthorizationApiMixin.php   # store:{resource}:{action} voter + storeScopedFilter
|   `-- StoreManagerAuthorizationApiMixin.php  # owner/manager membership gate
`-- Resources/JsonSchema/
    |-- StoreSettings.json
    |-- StoreAddress.json
    `-- StoreContact.json
```

Attribute routes are mounted under `/api/v1` by `config/routes.yaml` (`api_store`);
service wiring (including the Trade-owned `StoreContextResolverInterface` and
`CatalogResolverInterface` ports) lives in `config/services.yaml`. There is no
`Exception/` directory and no `services_store.yaml`; `StoreContext` is owned by Trade
(`App\Trade\DTO\StoreContext`). Inventory-specific implementation classes
are intentionally deferred until the inventory design is approved.

---

## 5. Entity Design

### 5.1 Store

**Table:** `store`

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `uuid` | string(36), unique | Yes | Stable public/cross-service identifier |
| `code` | string(50), unique | Yes | Immutable machine-readable store code |
| `name` | string(255) | Yes | Current display name |
| `status` | string(30) | Yes | `active`, `suspended`, `closed` |
| `timezone` | string(64) | Yes | IANA zone, such as `Asia/Shanghai` |
| `currency` | string(32) | Yes | Store currency, default `CNY` (added `Version20260903000004`; pre-existing rows backfilled to `LIANSHENG_POINT`); carried into `StoreContext` and enforced at Trade quote/order time |
| `contact` | json nullable | No | Sanitized contact data |
| `address` | json nullable | No | Structured address/geolocation data |
| `settings` | json nullable | No | Store-local configuration, not secrets — see §5.1.1 |
| `createdAt` | datetime_immutable | Yes | Creation time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Rules:

- `uuid` is the only cross-service store identifier.
- `code` is stable after activation because Promotion and external channels may use it.
- `closed` stores cannot be selected for new orders.
- Historical orders use snapshots, so Store deletion is forbidden; closure is a status.

#### 5.1.1 Store Settings Schema (single flow, default `false`)

`settings` is validated by `Store/Resources/JsonSchema/StoreSettings.json` (via `Manage/StoreController` + `Core/Validator/JsonSchemaValidator`) and parsed by `Store/DTO/StoreSettings`. Unknown top-level keys are tolerated for forward compatibility. `order.requireAcceptance` has been removed (the Manage controller still tolerates a legacy `order` object without effect).

```json
{
  "fulfillment": { "requireVerification": false }
}
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `fulfillment.requireVerification` | `bool` | `false` | `false` → Trade `fulfilled --complete--> completed` directly (`_completionMode=manual`). `true` → Trade `fulfilled` can only `complete` via Store verification (`_completionMode=store_verification`). `StoreOrder.verificationRequired` is snapshotted at creation; Trade completion is gated by `Trade/EventListener/OrderCompletionGuardListener` (blocks `complete` unless `Order::allowCompletionFromStoreVerification()` has been called) and `Trade/EventListener/OrderVerificationCompletionListener` (auto-completes after `fulfill` if `_storeVerificationReceived=true`). Store side: `POST /api/v1/store/{scopeId}/orders/{uuid}/verify` (`StoreOrderService::verify` → `store.order.verified.v1` → `Trade/MessageHandler/StoreOrderVerifiedHandler`). |

Validation:

- `settings` must be `object|null`; `fulfillment` must be `object|null` when present.
- `fulfillment.requireVerification` must be `bool` when present.
- `null` or missing `settings` is treated as `false` (legacy stores).
- The value is **snapshotted per order** into `TradeOrder.metadata._store.requireVerification`, `TradeOrder.metadata._completionMode`, and `StoreOrder.verificationRequired` at `trade.order.created.v1` time. Changing `Store.settings` after order creation does **not** affect inflight orders (immutable per-order verification requirement).
- `settings.liansheng` (object, tolerated by the Manage controller and the JSON schema's top-level `additionalProperties:true`) carries the Liansheng vendor configuration consumed by `Liansheng/Service/LianshengService::configuration()`: required `baseUrl/appCode/appSecret/userId` plus optional `memberCardTypeId` (string; required at member-registration time). It is keyed per Store via `X-Store-Code` against an active Store.

Example — enable verification:

```bash
curl -X PUT http://localhost:8080/api/v1/manage/stores/{uuid} \
  -H "Content-Type: application/json" -d '{"settings":{"fulfillment":{"requireVerification":true}}}'
```

#### 5.1.2 Store Address / Contact Schemas (standardized via JSON Schema)

`address` and `contact` are validated in the API layer **before** `processCreateContent` via `Core/Validator/JsonSchemaValidator` driven by `CreateApiViewMixin`/`UpdateApiViewMixin` (`$jsonSchemas`). Schemas live in `src/Store/Resources/JsonSchema/`; validator lives in `Core`.

```php
// Manage/StoreController
protected array $jsonSchemas = [
    'address'  => 'Store/StoreAddress',
    'contact'  => 'Store/StoreContact',
    'settings' => 'Store/StoreSettings',
];
```

- **`StoreAddress.json`** — `province/city/district/street/detail/building/floor/postalCode/formattedAddress` (strings, `maxLength`), `latitude [-90,90]` + `longitude [-180,180]` (`number`) with `dependencies: latitude↔longitude`, `geohash` regex, `poiId`; `type: object`, `additionalProperties:false`, `null` skipped (field is nullable). Extra keys → `400`.
- **`StoreContact.json`** — `phone/managerPhone` regex, `email/managerEmail` `format:email`, `managerName`, `managerUserUuid` `format:uuid`, `wechat/serviceHours`, `subTitle`, `tags[]`; `additionalProperties:false`.
- **`StoreSettings.json`** — `fulfillment.requireVerification` (`bool`, default `false`); top-level `additionalProperties:true` for forward compat, inner `fulfillment` `additionalProperties:false`. No `order` key; `order.requireAcceptance` was removed.

All `json` columns remain nullable; the schemas provide the **API contract** while preserving the current `1w`-store `json` storage and `Cache`-based distance calculation (no `latitude/longitude` columns yet). Violations throw `JsonSchemaViolationException` → `400`.

### 5.2 Membership

**Table:** `store_membership`

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `store` | ManyToOne Store | Yes | Local Store relation |
| `userUuid` | string(36) | Yes | Identity User UUID scalar; no foreign key |
| `role` | string(30) | Yes | `owner`, `manager`, `clerk`, `fulfillment` |
| `status` | string(30) | Yes | `active`, `suspended`, `revoked` |
| `createdAt` | datetime_immutable | Yes | Grant time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Constraints and rules:

- Unique `(store_id, user_uuid)`.
- Index `(user_uuid, status)` for authorization lookup.
- A membership role is local to one Store and MUST NOT be written into `Identity\User.roles`.
- `ROLE_ADMIN` remains a platform-wide override; Store membership supports store-scoped
  operations only.

### 5.3 StoreOrder

**Table:** `store_order`

`StoreOrder` is the Store-side aggregate created from one Trade order-created event.
It is not a second commercial order.

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `uuid` | string(36), unique | Yes | Store order public identifier |
| `tradeOrderUuid` | string(36), unique | Yes | Immutable Trade order reference |
| `store` | ManyToOne Store | Yes | Local Store relation |
| `storeCodeSnapshot` | string(50) | Yes | Code at placement time |
| `storeNameSnapshot` | string(255) | Yes | Name at placement time |
| `customerUserUuid` | string(36) nullable | No | Scalar customer Identity UUID reference |
| `currency` | string(32) | Yes | Copied for display/validation (expanded `VARCHAR(10)->VARCHAR(32)` by `Version20260903000005` alongside `trade_order`/`payment_invoice`) |
| `totalAmount` | bigint | Yes | Immutable copied commercial amount in cents |
| `orderSnapshot` | json | Yes | Immutable line and delivery snapshot from event |
| `operationalStatus` | string(40) | Yes | Store operational state machine |
| `rejectionCode` | string(50) nullable | No | Machine-readable rejection reason |
| `rejectionReason` | text nullable | No | Sanitized operator/customer reason |
| `acceptedAt` | datetime_immutable nullable | No | Store acceptance time |
| `rejectedAt` | datetime_immutable nullable | No | Store rejection time |
| `fulfillmentData` | json nullable | No | Pickup/delivery/assignment data, Store-owned |
| `reservationId` | string(64) nullable | No | Future inventory reservation reference |
| `verificationRequired` | bool | Yes | Immutable snapshot of `settings.fulfillment.requireVerification` at projection time (from `trade.order.created.v1` `store.requireVerification`). Default `false`. Controls whether `verify` is allowed. |
| `verifiedAt` | datetime_immutable nullable | No | Store verification time (set by `POST /api/v1/store/{scopeId}/orders/{uuid}/verify`; requires `verificationRequired=true` and `operationalStatus=fulfilled`) |
| `verifiedBy` | string(36) nullable | No | Verifying staff `userUuid` (audit) |
| `createdAt` | datetime_immutable | Yes | Projection creation time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Indexes:

| Index | Purpose |
|---|---|
| Unique `trade_order_uuid` | Business idempotency: one StoreOrder per Trade order |
| `(store_id, operational_status, created_at)` | Store work queue and reporting |
| `(customer_user_uuid, created_at)` | Customer read views |
| `(reservation_id)` | Future inventory reconciliation |

`totalAmount`, `currency`, and `orderSnapshot` are copied facts for Store operations.
Trade remains authoritative if they ever differ; a mismatch is an alert-worthy contract
violation, not a Store-side repricing opportunity.

### 5.4 Store Operational Statuses

| Status | Meaning | Owner |
|---|---|---|
| `pending_validation` | Event received; Store checks eligibility (transient, immediately accepted when `INVENTORY_ENABLED=0`) | Store |
| `awaiting_inventory` | Store validated; reservation pending (only when `INVENTORY_ENABLED=1`) | Store/Inventory integration |
| `accepted` | Projection accepted (auto-accepted when `INVENTORY_ENABLED=0`); Store can fulfill | Store |
| `rejected` | Reserved for future inventory rejection path only | Store |
| `fulfillment_pending` | Commercial payment complete; Store may prepare work | Store |
| `fulfilling` | Store is actively preparing/dispatching | Store |
| `fulfilled` | Store reports local fulfillment complete | Store |
| `verified` | Store verification completed (`fulfilled -> verified` via `POST .../verify`); emits `store.order.verified.v1` | Store |
| `cancelled` | Store operation stopped after Trade cancellation | Store |

Notes:

- In the current simplified coupling, `pending_validation -> accepted` is auto-applied by `Store/MessageHandler/TradeOrderCreatedHandler` when `INVENTORY_ENABLED=0`. No `store.order.accepted.v1` / `rejected.v1` is emitted.
- When `verificationRequired=true`, the only Store verification transition is `fulfilled -> verified`. Verification is checked against the immutable `StoreOrder.verificationRequired` snapshot, not live `Store.settings`.
- The Store workflow MUST NOT use `paid`, `refunded`, or `completed` as its own state
  names. Those remain Trade commercial states.

### 5.5 Outbox And Inbox Entities

#### StoreOutboxMessage

**Table:** `store_outbox_message`

| Field | Type | Description |
|---|---|---|
| `id` | bigint | Internal sequence |
| `eventId` | string(36), unique | Globally unique event identifier |
| `topic` | string(120) | e.g. `store.order.verified.v1` (`aggregateType=store_order`); also `inventory.reservation.requested.v1` / `inventory.reservation.release.requested.v1` (`aggregateType=inventory_reservation`) |
| `aggregateType` | string(80) | `store_order` |
| `aggregateId` | string(64) | StoreOrder UUID |
| `payload` | json | Event envelope/payload, safe to serialize |
| `occurredAt` | datetime_immutable | Business event time |
| `availableAt` | datetime_immutable | Retry scheduling time |
| `publishedAt` | datetime_immutable nullable | Delivery acknowledgement time |
| `attempts` | int | Relay attempt counter |
| `lastError` | text nullable | Sanitized relay error |

#### StoreConsumedEvent

**Table:** `store_consumed_event`

| Field | Type | Description |
|---|---|---|
| `id` | bigint | Internal sequence |
| `eventId` | string(36), unique | Incoming integration event id |
| `topic` | string(120) | Original topic |
| `aggregateId` | string(64) | Source aggregate id |
| `processedAt` | datetime_immutable | Successful local processing time |
| `payloadHash` | string(64) | SHA-256 of canonical payload for diagnostics |

Inbox uniqueness prevents replayed broker messages from repeating Store decisions. The
business unique key on `StoreOrder.tradeOrderUuid` remains mandatory defense in depth.

---

## 6. Trade Order State Machine Extension

### 6.1 Target States

Trade `order` workflow is intentionally simple and does **not** carry a Store acceptance branch. See `config/packages/workflow.yaml` and `src/Trade/Entity/Order.php`.

```text
draft -> pending -> confirmed -> paid -> fulfilled -> completed
draft -> cancelled
pending -> cancelled
confirmed -> cancelled
paid -> refunded
fulfilled -> completed
```

- There are no `awaiting_store_acceptance`, `store_accepted`, `store_rejected`, `awaiting_store_verification`, `store_submit`, `store_accept`, `store_reject`, `request_verification`, or `store_verify` places/transitions.
- `Trade/Service/OrderService::createOrder` always applies `submit` (`draft -> pending`) when a `StoreContext` is present, persists `_store` + `_completionMode` into `metadata` (see §3.2), and always writes a `trade.order.created.v1` outbox row.
- Store verification does **not** introduce workflow states; it gates the existing `fulfilled -> completed` transition via guards (see below).

**Store verification completion (single optional flow) — `fulfillment.requireVerification`**

| `fulfillment.requireVerification` | `metadata._completionMode` | Trade completion behavior |
|---|---|---|
| `false` (default) | `manual` | `fulfilled --complete--> completed` directly. No Store verification required. |
| `true` | `store_verification` | `fulfilled --complete--> completed` only when `Order::isCompletingFromStoreVerification()=true`. Direct `complete` is blocked by `Trade/EventListener/OrderCompletionGuardListener`. Verification fact arrives via `store.order.verified.v1` → `Trade/MessageHandler/StoreOrderVerifiedHandler` (sets `_storeVerificationReceived=true` and calls `allowCompletionFromStoreVerification()` → `workflow->apply(complete)`). Out-of-order case: if `verified.v1` arrives before `fulfilled`, the handler stores `_storeVerificationReceived` and `Trade/EventListener/OrderVerificationCompletionListener` auto-completes right after `fulfill`. |

Store side: `POST /api/v1/store/{scopeId}/orders/{uuid}/verify` (no `verificationCode`; UUID is the verification token) checks `StoreOrder.isVerificationRequired()` (immutable snapshot) and `operationalStatus=fulfilled`, then transitions `fulfilled -> verified` and emits `store.order.verified.v1` with `verifiedBy/verifiedAt`.

Invariants:

1. Trade always emits `trade.order.created.v1` when a `StoreContext` exists, after it has an immutable order UUID and Store snapshot (including `requireVerification`).
2. Payment is **not** gated on Store state. `pending -> confirmed -> paid` proceeds normally.
3. Store projection is eventual; `TradeOrderCreatedHandler` auto-accepts (`pending_validation -> accepted`) when `INVENTORY_ENABLED=0`.
4. Verification consumers are idempotent (`StoreConsumedEvent` + `workflow.can()` checks + store-uuid match).
5. When `_completionMode=store_verification`, `fulfilled` cannot `complete` without the Store verification flag (`OrderCompletionGuardListener` + `OrderVerificationCompletionListener`).

### 6.2 Payment Gate

No Store gate exists. The Trade payment service checks standard `order.status` eligibility (`pending`, `confirmed`, etc.). Payment endpoints do **not** return a conflict for Store-scoped orders awaiting any Store step. This was changed when `awaiting_store_acceptance` was removed.

### 6.3 Order Creation Response

`POST /api/v1/app/orders` with `X-Store-Code` always creates a `pending` order and returns `201` `Order created` (even for Store-scoped orders). A `trade.order.created.v1` outbox row is always written when `StoreContext` exists:

```json
{
  "data": {
    "orderUuid": "trade-order-uuid",
    "status": "pending",
    "store": {
      "uuid": "store-uuid",
      "name": "Xuhui Store"
    }
  },
  "code": 201,
  "message": "Order created"
}
```

The Store projection is created asynchronously; the client polls `GET /api/v1/app/store-orders/{uuid}` or the Trade order detail endpoint. Store availability does not affect the HTTP status; unavailable Store handling is via async retry/DLQ (see §8.1).

For completion: `fulfilled --complete--> completed` is direct when `fulfillment.requireVerification=false` (`_completionMode=manual`). When `true` (`_completionMode=store_verification`), the client/staff must trigger `POST /api/v1/store/{scopeId}/orders/{uuid}/verify` and the Trade order auto-completes on `store.order.verified.v1` (see §7.5 and §8.1).

---

## 7. Integration Event Contracts

### 7.1 Envelope

All integration events use this envelope:

```json
{
  "eventId": "uuid",
  "type": "trade.order.created",
  "version": 1,
  "occurredAt": "2026-07-24T12:00:00+00:00",
  "aggregateType": "trade_order",
  "aggregateId": "trade-order-uuid",
  "correlationId": "trade-order-uuid",
  "causationId": null,
  "payload": {}
}
```

Rules:

- `eventId` is immutable and globally unique.
- `aggregateId` is the aggregate UUID, never an internal database id.
- `correlationId` follows the business flow across services; for this flow it begins as
  the Trade order UUID.
- `causationId` is the triggering event id, or `null` for an initial HTTP command.
- A breaking payload change creates a new event version/topic; it never mutates v1.
- Payloads must contain only data safe for the receiving domain. Do not publish secrets,
  payment credentials, raw provider callbacks, or unnecessary PII.

### 7.2 `trade.order.created.v1`

**Publisher:** Trade outbox relay after order creation commits.

**Consumer:** Store `TradeOrderCreatedHandler` (`Store/MessageHandler/TradeOrderCreatedHandler`).

```json
{
  "orderUuid": "trade-order-uuid",
  "store": {
    "uuid": "store-uuid",
    "code": "shanghai-xuhui",
    "name": "Xuhui Store",
    "channel": "mini_program",
    "currency": "CNY",
    "requireVerification": false
  },
  "customerUserUuid": "identity-user-uuid",
  "currency": "CNY",
  "totalAmount": 12800,
  "items": [
    {
      "lineId": "trade-order-item-uuid",
      "catalogReference": "SKU-001",
      "quantity": 2,
      "unitPrice": 6400,
      "lineAmount": 12800,
      "snapshot": {}
    }
  ],
  "delivery": {},
  "placedAt": "2026-07-24T12:00:00+00:00"
}
```

- `store.requireVerification` is the snapshot of `Store.settings.fulfillment.requireVerification` at order creation (see §3.2, §5.1.1, §6.1). Store persists it as `StoreOrder.verificationRequired`; Trade persists it as `metadata._store.requireVerification` and derives `metadata._completionMode`.

The event is a historical placement snapshot. Store does not re-read Trade tables to
complete the payload.

### 7.3 `store.order.accepted.v1` — Removed

> **Removed in the simplified coupling.** The Store no longer emits `store.order.accepted.v1`. `StoreOrder` is auto-accepted synchronously in `Store/MessageHandler/TradeOrderCreatedHandler` when `INVENTORY_ENABLED=0`; when `INVENTORY_ENABLED=1` it enters `awaiting_inventory` and future inventory confirms it. No `store_accept` workflow transition exists. Historical payload for reference:

```json
{
  "orderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "storeUuid": "store-uuid",
  "acceptedAt": "2026-07-24T12:01:00+00:00",
  "reservationId": "inventory-reservation-uuid"
}
```

### 7.4 `store.order.rejected.v1` — Removed

> **Removed in the simplified coupling.** The Store no longer emits `store.order.rejected.v1`. Rejection reasons below are retained only as a future inventory-rejection vocabulary; Trade does not consume a Store rejection event and does not transition to `cancelled` from a Store event.

Previously allowed `reasonCode` values:

| Code | Meaning |
|---|---|
| `STORE_NOT_FOUND` | Resolved Store no longer exists; indicates configuration drift |
| `STORE_UNAVAILABLE` | Suspended, closed, or not accepting the channel |
| `ITEM_NOT_AVAILABLE` | Store cannot sell one or more requested items |
| `OUT_OF_STOCK` | Inventory reservation rejected |
| `DELIVERY_NOT_SUPPORTED` | Delivery method/address not serviceable |
| `ACCEPTANCE_TIMEOUT` | Store decision did not arrive before the Trade deadline |
| `SYSTEM_ERROR` | Retry budget exhausted; operator action required |

### 7.5 Implemented Verification Event

**`store.order.verified.v1`** (Store → Trade) is implemented when the order's snapshotted `verificationRequired=true` (i.e. `Store.settings.fulfillment.requireVerification=true` at order creation time, `metadata._completionMode=store_verification`).

```json
{
  "orderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "storeUuid": "store-uuid",
  "verifiedBy": "staff-user-uuid",
  "verifiedAt": "2026-09-03T08:00:00+00:00"
}
```

- No `verificationCode`. Verification uses the order UUID as the verification token: `POST /api/v1/store/{scopeId}/orders/{uuid}/verify` takes no body (empty JSON `{}` accepted). The controller resolves the StoreOrder by trade order UUID (fallback: StoreOrder UUID) scoped to the URL Store and checks membership scope.
- Preconditions in `Store/Service/StoreOrderService::verify()`: `isVerificationRequired()=true` and `operationalStatus=fulfilled`; otherwise `LogicException`. On success transitions `fulfilled -> verified` (`StoreOrder::verify()` sets `verifiedAt/verifiedBy`) and records `store.order.verified.v1` via `store_outbox_message` (`Store/Service/StoreOutboxService`).
- Consumer: `Trade/MessageHandler/StoreOrderVerifiedHandler` (async `StoreOrderVerifiedMessage`). Checks `order.metadata._store.uuid === payload.storeUuid` and `metadata._completionMode === 'store_verification'`. Inside a transaction sets `metadata._storeVerificationReceived=true`, calls `Order::allowCompletionFromStoreVerification()`, then `workflow->can(complete)` → `workflow->apply(complete)` if `fulfilled`; otherwise the flag remains and `Trade/EventListener/OrderVerificationCompletionListener` completes after `fulfill` (out-of-order handling). Idempotent via `workflow.can()` and repeated flag writes.
- Audit fields on the entity are `verifiedAt/verifiedBy` only; there is no `verificationCode` field and the verify endpoint takes no code (the order UUID is the token). Note: `Version20260903000003` also created an unused `verification_code` column that no code reads or writes.

### 7.6 Future Events

The following remain reserved (Store-side consumers for the inventory trio are already implemented; see §12):

| Event | Publisher | Purpose |
|---|---|---|
| `trade.order.paid.v1` | Trade | Allow Store to begin preparation/fulfillment |
| `trade.order.refunded.v1` | Trade | Stop or reverse Store work where allowed |
| `store.order.fulfilled.v1` | Store | Tell Trade fulfillment completed, if Trade retains its workflow transition (currently Store `fulfill` is local only) |
| `inventory.reservation.confirmed.v1` | Inventory | Confirm Store-requested reservation (consumed by implemented `Store/MessageHandler/ReservationConfirmedHandler` → `awaiting_inventory -> accepted`) |
| `inventory.reservation.rejected.v1` | Inventory | Reject Store-requested reservation (consumed by implemented `Store/MessageHandler/ReservationRejectedHandler` → `reject(reasonCode, reason)`) |
| `inventory.reservation.released.v1` | Inventory | Reservation release acknowledgement (consumed by implemented `Store/MessageHandler/ReservationReleasedHandler` for inbox dedup; no state change) |

`trade.order.cancelled.v1` is already implemented (Trade `OrderWorkflowListener` on `cancel`).

---

## 8. Consumer And Publisher Design

### 8.1 Trade Order Created Consumer

`Store/MessageHandler/TradeOrderCreatedHandler` (`TradeOrderCreatedMessage`) processes one message as follows:

```text
receive message (trade.order.created.v1)
  -> validate envelope and schema version (eventId + payload)
  -> start Store local transaction
  -> insert StoreConsumedEvent(eventId), or return if already present (inbox dedup)
  -> find Store by payload.store.uuid; if not found or not active, throw RuntimeException
     (triggers Messenger retry; not a business rejection)
  -> create/find StoreOrder by tradeOrderUuid via StoreOrderService::createFromTradeOrderSnapshot
     (includes verificationRequired snapshot from payload.store.requireVerification;
      idempotent on duplicate tradeOrderUuid with same snapshot; LogicException on mismatch)
  -> if StoreTradeOrderCancellation exists for this tradeOrderUuid -> StoreOrder.cancel()
     (a tombstone whose storeUuid differs from the event snapshot throws `LogicException`)
  -> if StoreOrder.operationalStatus != pending_validation -> return (already handled)
  -> if !INVENTORY_ENABLED -> StoreOrderService::accept() (pending_validation -> accepted, no outbox)
  -> else -> StoreOrder.awaitInventory(reservationId) + StoreOutboxService.record('inventory.reservation.requested.v1')
  -> commit
  -> acknowledge broker message
```

No `store.order.accepted.v1` / `rejected.v1` is emitted. The unavailable-Store path throws `RuntimeException('Store is not available.')` so the broker retries rather than committing a rejected projection.

The broker message is acknowledged only after the Store transaction commits. If the
process crashes before acknowledgement, the event is delivered again and becomes an
inbox/business-idempotent no-op.

### 8.2 Outbox Publisher

The outbox publisher is a transport adapter, not business logic:

```text
poll unpublished outbox messages in created order
  -> lock one batch
  -> publish envelope to transport topic
  -> mark publishedAt on broker acknowledgement
  -> on transient error: increment attempts and retry later
  -> on retry exhaustion: retain record and alert/DLQ
```

Initial deployment may use Symfony Messenger with Doctrine transport. The business
contract must not depend on that choice. RabbitMQ, Kafka, SQS, or an HTTP event relay
can replace the transport without changing StoreOrder business code.

Note the envelope asymmetry: the Trade publisher (`Trade/Command/PublishOutboxCommand`)
emits the full §7.1 envelope (`occurredAt/aggregateType/correlationId/causationId`), while
the Store publisher (`Store/Command/PublishOutboxCommand`, `app:store:outbox:publish`)
dispatches a reduced envelope (`eventId/type/version/aggregateId/payload`) mapped by topic
to `StoreOrderVerifiedMessage`, `ReservationRequestedMessage`, or
`ReservationReleaseRequestedMessage` (unknown topics are deferred with `lastError`).

### 8.3 Retry Classification

| Failure | Consumer action |
|---|---|
| Duplicate `eventId` | Acknowledge; no mutation (inbox hit) |
| Duplicate `tradeOrderUuid`, same snapshot | Continue idempotently; return existing StoreOrder |
| Duplicate `tradeOrderUuid`, different snapshot | Do not overwrite; throw `LogicException` → critical alert and DLQ |
| Temporary database/broker failure | Roll back and retry |
| Invalid event schema/version | DLQ; do not retry blindly (`InvalidArgumentException`) |
| Store not found / not active | Throw `RuntimeException('Store is not available.')` → retry with backoff (Messenger retry), then DLQ |
| Inventory rejection | Commit `rejected` StoreOrder via `ReservationRejectedHandler` (`reasonCode/reason` from payload) |
| Unexpected domain exception | Roll back; retry with bounded backoff, then DLQ |

### 8.4 Ordering And Concurrency

- The Store consumer serializes concurrent decisions by `tradeOrderUuid` through the
  unique StoreOrder key and transaction locking where necessary.
- An event topic should preserve ordering per aggregate key where the transport supports
  it, but correctness must not rely on global ordering.
- Store verification after a Trade `fulfilled` is the only cross-bundle result event; `store.order.verified.v1` is idempotent via `_storeVerificationReceived` + `workflow.can(complete)`.
- Trade cancellation is consumed by `Store/MessageHandler/TradeOrderCancelledHandler` (`StoreTradeOrderCancellation` + `StoreOrder.cancel()`), ensuring late verification is ignored.
- All result handlers validate expected source state before applying transitions.

---

## 9. Store APIs

### 9.1 Public Store Discovery

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/app/stores` | ROLE_USER | Discover selectable active stores (list filtered to `status=active`) |
| GET | `/api/v1/app/stores/{uuid}` | ROLE_USER | Read store details and availability |

These endpoints do not create orders. The storefront uses the selected Store context
when calling the existing Trade quote/order APIs.

### 9.2 Customer Store Order And Membership Views

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/app/store-orders/{uuid}` | ROLE_USER | Read Store operational information for own commercial order |
| POST | `/api/v1/app/stores/{uuid}/membership` | ROLE_USER | Join store as member (self-service, idempotent; fixed `role=clerk`, `status=active`; `201` new, `200` reactivated/already member) |
| GET | `/api/v1/app/stores/{uuid}/membership` | ROLE_USER | Read own membership for a store |

The controller resolves ownership through the `customerUserUuid` stored in StoreOrder.
It must not reveal operational notes, employee IDs, inventory internals, or internal
rejection diagnostics.

The API is a convenience read model; the customer still uses Trade order APIs for
payment, cancellation, refunds, and canonical commercial status.

### 9.3 Platform Store Administration

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET/POST/PUT | `/api/v1/manage/stores` | ROLE_ADMIN | Store CRUD (create requires `code/name/timezone`; update accepts `name/timezone/currency/contact/address/settings`); closure instead of delete |
| POST | `/api/v1/manage/stores/{uuid}/status/{activate\|suspend\|close}` | ROLE_ADMIN | Store lifecycle transition |
| GET/POST | `/api/v1/manage/stores/{uuid}/members` | ROLE_ADMIN | List memberships / grant (or reactivate) a membership by `userUuid`+`role`; no revoke endpoint (revocation is via `Membership.revoke()` in code, unexposed) |
| GET | `/api/v1/manage/store-orders` | ROLE_ADMIN | Cross-store operational reporting |
| GET | `/api/v1/manage/store-orders/{uuid}` | ROLE_ADMIN | Operational detail |

### 9.4 Store Staff Operations

Store staff must not use generic Trade `manage/orders` routes, which are platform-wide
`ROLE_ADMIN` endpoints. Store provides scoped operational endpoints:

| Method | Path | Required membership | Purpose |
|---|---|---|---|
| GET | `/api/v1/store/{scopeId}/orders` | active member (`store:order:read`) | Current store work queue (scoped to the URL Store) |
| GET | `/api/v1/store/{scopeId}/orders/{uuid}` | active member (`store:order:read`) | StoreOrder operational detail (lookup by trade order UUID, fallback StoreOrder UUID) |
| POST | `/api/v1/store/{scopeId}/orders/{uuid}/fulfill` | fulfillment/manager/owner (`store:order:fulfill`) | Mark Store operation `fulfilled` (from `accepted`/`fulfillment_pending`/`fulfilling`; optional `fulfillmentData` object). Triggers `OrderVerificationCompletionListener` auto-complete if `_storeVerificationReceived` already true. |
| POST | `/api/v1/store/{scopeId}/orders/{uuid}/verify` | fulfillment/manager/owner (`store:order:verify`) | Store verification post-fulfill — requires `StoreOrder.isVerificationRequired()=true` (snapshotted, not live settings) and `operationalStatus=fulfilled`; body is empty (`{}`); uses order UUID as verification token. Transitions `fulfilled -> verified` and emits `store.order.verified.v1` with `verifiedAt/verifiedBy` (no `verificationCode`). Staff `userUuid` is taken from the authenticated Identity user. |
| GET/POST | `/api/v1/store/{scopeId}/assignments` | owner/manager membership | Lists active Store-scoped assignments and grants a Store-scoped role (`userUuid`+`roleUuid`; grantee must already be an active member of that Store; scope fixed by URL) |
| DELETE | `/api/v1/store/{scopeId}/assignments/{assignmentUuid}` | owner/manager membership | Revokes a Store-scoped assignment in the current Store only (`204`) |
| GET | `/api/v1/store/{scopeId}/assignable-roles` | owner/manager membership | Lists Store-scoped roles (`scopeType=store`) the manager may grant, with their permissions. |
| GET | `/api/v1/store/{scopeId}/members` | owner/manager membership | Lists non-revoked Store memberships with user display info (`uuid/username/nickname/phone`). |
| GET/POST/PUT/DELETE | `/api/v1/store/{scopeId}/products` | ROLE_USER + `store:product:*` voter | Store-private product CRUD (store fixed by URL; delete is soft `isDeleted`) |
| GET/POST/PUT/DELETE | `/api/v1/store/{scopeId}/products/{productUuid}/specifications` | ROLE_USER + `store:specification:*` voter | Specification CRUD under the URL Store's product (delete is soft `isDeleted`) |
| GET/PUT | `/api/v1/store/{scopeId}` | owner/manager membership | View and update the current Store profile (name, timezone, currency, contact, address, settings). |

> `POST .../accept` and `POST .../reject` have been removed. Store acceptance is automatic; rejection is deferred to the inventory path (`ReservationRejectedHandler`).

Row-scope mechanism: staff order/product/specification endpoints resolve the URL Store via `StoreScopedAuthorizationApiMixin::storeForAuthorization()` (identifier criteria on `scopeId`), filter by that Store (`storeScopedFilter`, e.g. `['store' => $store]`), and enforce `store:{resource}:{action}` permissions through `StoreAuthorizationVoter` (`MembershipService::isAuthorized` for active membership + `AuthorizationService::can` with the Store scope). There is no `FieldAuthorizationService` usage in Store. Manager endpoints (assignments, members, profile) instead require `owner`/`manager` membership via `StoreManagerAuthorizationApiMixin`. Catalog reads (`App` product/specification) use `DqlExpression` row-scope (see [Store Catalog Model](../store-catalog.md) §6.2). All actions check membership against the `StoreOrder`'s local `Store` relation, not a request-supplied store identifier. `fulfill`/`verify` are idempotent guards: `verify` fails with `LogicException` if not `fulfilled` or not `verificationRequired`.

---

## 10. Authorization Model

### 10.1 Roles

| Principal | Scope | Capabilities |
|---|---|---|
| `ROLE_ADMIN` | Platform | All Store administration and reporting |
| Store `owner` | One Store | Membership, store settings, fulfillment, verification |
| Store `manager` | One Store | Fulfillment, verification, operational views |
| Store `clerk` | One Store | Read operational queue; limited configured actions |
| Store `fulfillment` | One Store | Fulfillment-only operational actions |
| Customer | Own orders | Read customer-safe StoreOrder view only |

### 10.2 Authorization Rules

1. Platform roles live in Identity and retain their existing meaning.
2. Store roles live only in `Membership`.
3. A Store controller resolves the authenticated Identity user to a scalar `userUuid` and
   queries Store membership locally.
4. `ROLE_ADMIN` bypasses membership checks only on explicitly administrative routes.
5. Store staff cannot elevate a Trade order's commercial status directly.
6. Store staff actions (`fulfill`, `verify`) produce Store events; Trade applies commercial `complete` only through `StoreOrderVerifiedHandler` + `OrderCompletionGuardListener`/`OrderVerificationCompletionListener` and workflow guards.
7. Store owners and managers may manage assignments only in their own Store. Administrators define Store-scoped roles and their permissions through the existing Manage Authorization APIs. Managers may grant any Store-scoped role; they cannot create global assignments, edit role definitions, or assign global roles.

---

## 11. Store Context And Promotion Contract

Promotion currently uses a `storeCode` within Trade's price calculation context. The
Store bundle gives that value an authoritative source:

```text
request -> StoreContextResolver -> active Store -> StoreContext.storeCode
        -> Trade calculatePrices(..., storeCode, ...)
        -> Promotion storeCode filtering
```

Rules:

- The client cannot select an arbitrary promotion store by posting `storeCode`.
- Trade passes the resolver-generated code to the pricing pipeline.
- A Store snapshot is persisted with the order-created event so the Store consumer can
  validate that the order was priced for the intended Store.
- Trade quote/order creation uses the resolved Store currency and rejects a mismatching
  requested currency (`Currency mismatch`, `400`); the Store snapshot (`_store.currency`,
  `trade.order.created.v1` `currency`) carries the enforced currency.
- Promotion remains independent: it does not reference Store tables or entities.
- A global promotion remains represented by the existing empty store code convention;
  Store selection must never accidentally turn an unknown store into global pricing.

This design makes the existing `Promotion.storeCode` a routing input, not the Store
domain model or authorization mechanism.

---

## 12. Inventory Boundary

Inventory quantity ownership is deliberately excluded from the Store data model, but the Store↔Inventory command/result boundary is implemented (gated by `INVENTORY_ENABLED`, default `0` in `.env`):

```text
Store validates order (TradeOrderCreatedHandler)
  -> if INVENTORY_ENABLED=0: auto-accept (pending_validation -> accepted), no inventory event
  -> if INVENTORY_ENABLED=1:
       inventory.reservation.requested.v1 (StoreOutboxService.record + app:store:outbox:publish)
       -> Inventory reserves quantity idempotently
       -> inventory.reservation.confirmed.v1  -> ReservationConfirmedHandler: StoreOrder.accept() (awaiting_inventory -> accepted)
            or inventory.reservation.rejected.v1 -> ReservationRejectedHandler: StoreOrder.reject(reasonCode, reason)
            or inventory.reservation.released.v1 -> ReservationReleasedHandler (inbox dedup only)
```

The implemented request payload (`TradeOrderCreatedHandler::inventoryItems` + `StoreOutboxService::record`) includes:

- `reservationId`: Store-generated idempotency/reference UUID.
- `storeUuid`.
- `tradeOrderUuid` and `storeOrderUuid`.
- `items`: `lineId`, `catalogReference` (specification UUID), `quantity` as a decimal string (`"2.000000"`); empty/invalid items throw `InvalidArgumentException`.
- `expiresAt` (`+30 minutes`) / `requestedAt` (`DATE_ATOM`).

Trade cancellation releases the reservation: `TradeOrderCancelledHandler` records `inventory.reservation.release.requested.v1` (`reservationId/storeUuid/tradeOrderUuid/storeOrderUuid/reason=trade_order_cancelled/requestedAt`) when the cancelled StoreOrder holds a `reservationId`.

Store owns the decision to request reservation and keeps the resulting `reservationId`.
Inventory owns available quantity, reservation ledger, decrement/release, and stock
reconciliation. Neither module accesses the other's database.

---

## 13. Timeout And Compensation

### 13.1 Cancellation (no acceptance timeout)

There is no acceptance deadline field, no `ACCEPTANCE_TIMEOUT` reason, and no scheduled acceptance-timeout job in code (the workflow has no acceptance states; `cancel` applies only to `draft/pending/confirmed`). Cancellation flows only from an explicit Trade `cancel` transition:

1. Trade applies `cancel` (`draft/pending/confirmed -> cancelled`) and, when the order carries a `_store.uuid` snapshot, `OrderWorkflowListener` writes `trade.order.cancelled.v1` (`orderUuid/storeUuid/cancelledAt`) to the Trade outbox.
2. `Store/MessageHandler/TradeOrderCancelledHandler` persists a `store_trade_order_cancellation` tombstone when no StoreOrder exists yet (a later `TradeOrderCreatedHandler` for the same `tradeOrderUuid` then immediately cancels the projection), otherwise calls `StoreOrder.cancel()` and, when a `reservationId` is held, records `inventory.reservation.release.requested.v1`.
3. Cancelled/rejected/fulfilled StoreOrders and store-UUID mismatches are ignored idempotently.

The handler is idempotent: already-cancelled orders and duplicate `eventId`s are no-ops.

### 13.2 Late Events

| Late event | Required behavior |
|---|---|
| Store `verify` after Trade cancellation | Store side: `verify` requires `fulfilled`, so a `cancelled` order fails with `LogicException`. Trade side: `StoreOrderVerifiedHandler` can no longer `complete` a `cancelled` order (`workflow.can()` is false); the `_storeVerificationReceived` flag write is harmless. |
| Store `verify` arriving before Trade `fulfilled` (out-of-order) | Trade stores `_storeVerificationReceived=true`; `OrderVerificationCompletionListener` completes right after `fulfill`. |
| Trade cancellation while Store awaits Inventory | Store records the `store_trade_order_cancellation` tombstone (or cancels the existing StoreOrder) and records `inventory.reservation.release.requested.v1` when a `reservationId` is held |
| Payment before Store projection exists | Allowed: payment is **not** gated on Store state. `pending -> confirmed -> paid` proceeds normally; the Store projection follows eventually. |

### 13.3 No Distributed Rollback

Once an event crosses an outbox boundary, a remote mutation cannot be rolled back.
Compensation is expressed as a new business event and local state transition, never a
cross-service database transaction.

---

## 14. Persistence And Migration Plan

### 14.1 Initial Store Migration

A Store migration creates only Store-owned tables:

```text
store                                                     # Version20260725010000 (+ currency Version20260903000004)
store_membership                                          # Version20260725010000
store_order                                               # Version20260725010000 (+ verified_at/verified_by/verification_code Version20260903000003, verification_required Version20260905000000, currency→VARCHAR(32) Version20260903000005)
store_outbox_message                                      # Version20260725010000
store_consumed_event                                      # Version20260725010000
store_trade_order_cancellation                            # Version20260726000000 (tombstone: unique trade_order_uuid)
```

It may include foreign keys from `store_membership.store_id` and `store_order.store_id`
to the local `store` table. It MUST NOT add foreign keys to `users`, `trade_order`,
`payment_invoice`, or future inventory tables.

### 14.2 Trade Migration

A separate Trade migration adds only generic order orchestration data required by the
new workflow, such as a Store snapshot (`_store`), `_completionMode`, and `_storeVerificationReceived` in existing `trade_order.metadata` JSON. No acceptance deadline field or Store FK is added.

### 14.3 Data Backfill

For existing orders without Store metadata:

- Treat them as legacy/global orders.
- Do not fabricate `StoreOrder` records unless a reliable external mapping exists.
- Existing payment/refund behavior remains unchanged.
- The simplified projection (auto-accept) and verification gate (`_completionMode`) apply only to newly created Store-scoped orders after this change.

---

## 15. Failure Handling And Observability

### 15.1 Error Categories

| Category | HTTP/Event behavior | Logging |
|---|---|---|
| Invalid Store context | Reject before Trade creates order | Warning with safe store hint |
| Store unavailable (not found / not active) | Consumer throws `RuntimeException('Store is not available.')` → retry/DLQ | Warning with storeUuid/tradeOrderUuid/attempt |
| Event schema violation | DLQ | Error with event id/topic |
| Consumer transient failure | Retry | Warning with attempt count |
| Snapshot mismatch (`tradeOrderUuid` conflicts on `storeCode`/`verificationRequired`/amount) | Do not mutate existing StoreOrder; throw `LogicException` → critical alert | Critical alert |
| Verification precondition failure (`!verificationRequired` or `status != fulfilled`) | HTTP 409/422 from `StoreOrderService::verify` | Warning with storeOrderUuid/verificationRequired/status |
| Outbox publishing failure | Retry/DLQ | Error with aggregate/event id |

### 15.2 Required Structured Log Fields

All asynchronous paths include:

```text
eventId, topic, aggregateId, correlationId, causationId,
tradeOrderUuid, storeOrderUuid, storeUuid, attempt
```

Logs must not contain customer phone/address details, payment secrets, raw gateway
payloads, or complete item snapshots unless explicitly redacted for secure diagnostics.

### 15.3 Operational Metrics

The target deployment exports at least:

- Outbox backlog count and oldest unpublished age.
- Consumer lag by topic.
- StoreOrder creation count by Store (auto-accept vs awaiting_inventory).
- Verification count by Store (`verified` status).
- Fulfillment-to-completion latency (manual vs store_verification).
- Inbox duplicate count.
- DLQ count and oldest message age.

---

## 16. Testing Contract

### 16.1 Unit Tests

| Suite | Required cases |
|---|---|
| `tests/Store/Entity/` | Store lifecycle, membership role/status, StoreOrder snapshots/statuses/verificationRequired, outbox/inbox fields |
| `tests/Store/Service/` | Context resolution, membership authorization, Store verify/fulfill guards (verificationRequired, fulfilled status), snapshot matching |
| `tests/Store/Consumer/` | Valid event (auto-accept), duplicate event, duplicate business key same/different snapshot, schema error, unavailable Store throws RuntimeException |
| `tests/Store/Outbox/` | Publish retry classification and event envelope serialization |
| `tests/Trade/` | Order `submit` always creates pending + outbox; `OrderCompletionGuardListener` blocks/permits `complete`; `StoreOrderVerifiedHandler` + `OrderVerificationCompletionListener` out-of-order handling |

### 16.2 Integration Tests

| Scenario | Expected result |
|---|---|
| Active Store receives valid Trade event (`INVENTORY_ENABLED=0`) | One `accepted` StoreOrder, no Store outbox (auto-accept) |
| Active Store with `requireVerification=true` | `StoreOrder.verificationRequired=true` snapshotted; Trade `metadata._completionMode=store_verification` |
| Store is closed / not found | Consumer throws `RuntimeException('Store is not available.')` → retry/DLQ; no StoreOrder committed |
| Same `eventId` redelivered | No second StoreOrder or outbox event (inbox dedup) |
| Different event ids with same Trade UUID/same payload | One StoreOrder; idempotent |
| Same Trade UUID/different payload (e.g. different `requireVerification` or amount) | No overwrite; `LogicException` → critical/DLQ |
| Store staff reads another store order | 404/403 without information leak |
| Customer reads another customer's StoreOrder | 404/403 without information leak |
| Store `fulfill` then `verify` (verificationRequired) | StoreOrder `fulfilled -> verified` + `store.order.verified.v1`; Trade `fulfilled -> completed` |
| Store `verify` before Trade `fulfilled` (out-of-order) | Trade stores `_storeVerificationReceived=true`; `OrderVerificationCompletionListener` completes after `fulfill` |
| Trade `fulfilled -> complete` without verification when `store_verification` | Blocked by `OrderCompletionGuardListener` (409/conflict) |
| Store `verify` when `verificationRequired=false` or `status != fulfilled` | 409/422 `LogicException` |

### 16.3 Contract Tests

Event producers and consumers need contract fixtures stored independently of Doctrine
serialization. Tests validate:

- Required fields and scalar types.
- v1 event compatibility.
- Unknown optional field tolerance.
- Forbidden sensitive fields absent from payload.
- Stable canonical payload hashing for inbox diagnostics.

---

## 17. Implementation Phases

### Phase 1: Store Foundation

1. Create Store, Membership, StoreOrder, Outbox, and Inbox entities/repositories.
2. Add Store service interfaces and services.
3. Implement platform Store administration and membership management.
4. Implement StoreContext resolution for the selected channel.
5. Add Store discovery endpoints and Store-staff authorization tests.

### Phase 2: Trade Orchestration Contract

1. Simplify Trade workflow to `draft -> pending -> confirmed -> paid -> fulfilled -> completed` (remove acceptance states).
2. Add server-generated Store snapshot (`_store` + `_completionMode` + `_storeVerificationReceived`) to new Trade orders.
3. Add a generic Trade integration-event outbox abstraction.
4. Always publish `trade.order.created.v1` when a `StoreContext` exists, after the Trade transaction commits; `draft -> pending` via `submit`.
5. Return `201` (`pending`) for Store-scoped order creation (no `202`).
6. Preserve legacy order behavior when no Store context is present, only if that legacy
   mode remains a product requirement.

### Phase 3: Store Consumer And Decision Events

1. Implement `Store/MessageHandler/TradeOrderCreatedHandler` with inbox and business idempotency.
2. Create StoreOrder from immutable event snapshot (including `verificationRequired`).
3. Auto-accept (`pending_validation -> accepted`) when `INVENTORY_ENABLED=0`; otherwise request inventory reservation (`awaiting_inventory`).
4. Retries on unavailable Store via `RuntimeException` (no rejection event).
5. Implement `StoreOrderService::fulfill` / `verify` and `store.order.verified.v1` outbox.
6. Implement `Trade/MessageHandler/StoreOrderVerifiedHandler` + `OrderCompletionGuardListener` / `OrderVerificationCompletionListener` (including out-of-order handling).

### Phase 4: Fulfillment And Inventory Integration

1. Define Inventory reservation event contracts.
2. Gate Store acceptance on reservation confirmation.
3. Add Store fulfillment operations and corresponding Trade event consumers where
   commercial workflow transitions remain Trade-owned.
4. Add cancellation/release compensation.

### Phase 5: Service Extraction Readiness

1. Move event schemas to a versioned contract package or JSON schema repository.
2. Replace in-process transport with a broker while retaining Outbox/Inbox behavior.
3. Move Store database and consumer independently.
4. Run consumer-driven contract tests in CI.
5. Add replay tooling and operational dashboards before enabling production extraction.

---

## 18. Acceptance Criteria

The Store bundle design is implemented when:

| Criterion | Requirement |
|---|---|
| Single order entry | No Store endpoint creates a commercial order; Trade remains the entry point |
| Store isolation | Store has no Doctrine association/FK to Trade, Payment, Identity, or Inventory |
| Context integrity | Store identity comes from a server-resolved StoreContext, not client body fields |
| Store projection | Exactly one StoreOrder is created per Trade order UUID, idempotently |
| Event isolation | Integration event payloads are versioned scalar/JSON contracts, never Doctrine entities |
| Reliable publication | Business mutation and local outbox write share one transaction |
| Reliable consumption | Inbox event-id uniqueness and StoreOrder business uniqueness protect retries |
| Payment gate | No Store gate; Trade payment eligibility is standard (no `awaiting_store_acceptance` block) |
| Rejection/timeout | No Store rejection event / acceptance timeout; unavailable Store is retried via DLQ, not cancelled |
| Verification gate | When `fulfillment.requireVerification=true`, `fulfilled -> completed` requires `store.order.verified.v1` (immutable per-order snapshot, `verificationRequired` + `_completionMode`) |
| Authorization | Store membership scopes `fulfill`/`verify`; platform admin retains explicit override |
| Inventory boundary | Store has reservation contract hooks but no embedded inventory ledger |
| Extraction readiness | Store can move to a separate database/consumer with no cross-schema joins or transactions |
