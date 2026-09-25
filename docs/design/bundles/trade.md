# Trade Bundle Design

> The Trade bundle (`src/Trade/`) owns orders, order items, price calculation, and the
> Symfony Workflow-based order state machine. Product and Specification are owned by
> `Store` (`src/Store/Entity/Product.php`, `src/Store/Entity/Specification.php`,
> tables `trade_product`/`trade_specification` retained) with nullable `store` (`NULL` =
> shared/global); see [Store Catalog Model](../store-catalog.md). `Trade` remains the
> commercial-order authority and references the Store catalog via scalar snapshots and
> `StoreContext`.

---

## 1. Overview

Trade provides a complete order management system:

- **Store-catalog integration** with `Store` Products and Specifications (SKU-like variants, `Product.store` nullable for shared/global)
- **Orders** with a state machine lifecycle (draft -> completed)
- **Order Items** with price snapshots for historical accuracy
- **Price Calculation Pipeline**: pluggable calculators with priority ordering (Store visibility enforced in `BasePriceCalculator`)
- **Soft Deletes**: products and specifications use `isDeleted` flag (Store entities)
- **UUID v4**: external identifiers for orders and items
- **Store integration**: Store-scoped orders write a local Outbox event and create a StoreOrder projection

### 1.1 Catalog Ownership

Product and Specification are `Store` entities (`Product.store` nullable). `StoreContext` (`X-Store-Code` → `Store`) is required for catalog reads, quotes, and orders; `BasePriceCalculator` accepts only shared (`store IS NULL`) or Store-owned specs for the resolved Store. See [Store Catalog Model](../store-catalog.md).

### 1.2 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Store\Product` | `trade_product` | Sellable product with name, description, status, nullable `store` |
| `Store\Specification` | `trade_specification` | Product variant (name, price in cents, status) |
| `Order` | `trade_order` | Purchase order with state machine, total, currency |
| `OrderItem` | `trade_order_item` | Line item with scalar `specificationUuid` (indexed, no FK), `specificationTitle`, `specSnapshot`/`productSnapshot`, quantity, unit/cost/profit prices (no `lineId` column — `lineId` is the outbox payload key for the item `uuid`) |
| `TradeOutboxMessage` | `trade_outbox_message` | Transactional integration event relay record (`event_id`, `topic`, `aggregate_type`, `aggregate_id`, `payload`, `occurred_at`/`available_at`/`published_at`, `attempts`, `last_error`); topics `trade.order.created.v1`, `trade.order.cancelled.v1` |

### 1.2 Store-Scoped Orders (optional, default off)

`POST /api/v1/app/orders` remains the sole customer order entry point. A trusted
`X-Store-Code` is resolved by the Store bundle into the Trade-owned scalar
`StoreContext`; Trade never imports a Store entity. The order always receives an `_store`
metadata snapshot and a `_completionMode` (`manual` or `store_verification` snapshotted from
`Store.settings.fulfillment.requireVerification`). The order enters `pending` via `submit`
regardless of Store presence and always writes `trade.order.created.v1` when a
`StoreContext` exists.

`app:trade:outbox:publish` dispatches the event through Messenger. Store consumes it
idempotently, creates its `StoreOrder` projection (with `verificationRequired` snapshot) and,
when inventory is disabled, auto-accepts immediately. Store verification
(`store.order.verified.v1`) is the only Store-to-Trade completion signal and is applied
via `Trade/EventListener/OrderCompletionGuardListener` and
`Trade/EventListener/OrderVerificationCompletionListener`.
`Trade/EventListener/OrderWorkflowListener` is status-driven for `completed` and does not
hard-code Store transition names. On `cancel` of a store-scoped order it also records
`trade.order.cancelled.v1` in the outbox. The status column is `VARCHAR(40)`.

---

## 2. File Structure

```
src/Trade/
|-- Controller/
|   |-- App/
|   |   `-- OrderController.php           # Public: list/create/quote/submit/confirm/payment/refund/cancel own orders
|   |-- Manage/
|       |-- OrderController.php            # CRUD + workflow + fulfill/refund + price calculation (no /pay endpoint)
|       `-- OrderItemController.php        # Order-item CRUD at /manage/order-items
|-- Entity/
|   |-- Order.php
|   |-- OrderItem.php  # scalar specificationUuid + snapshots (FK removed, irreversible)
|   `-- TradeOutboxMessage.php            # topics trade.order.created.v1 / trade.order.cancelled.v1
|-- Command/PublishOutboxCommand.php      # app:trade:outbox:publish (manual invocation, no scheduler)
|-- DTO/StoreContext.php                 # Store snapshot DTO consumed by Trade (resolved from X-Store-Code by Store)
|-- Event/ OrderPaidEvent.php, OrderFulfilledEvent.php, OrderCompletedEvent.php, OrderCancelledEvent.php, OrderRefundedEvent.php
|-- Message/ TradeOrderCreatedMessage.php, TradeOrderCancelledMessage.php, StoreOrderVerifiedMessage.php (inbound, dispatched by Store outbox)
|-- MessageHandler/StoreOrderVerifiedHandler.php  # sets _storeVerificationReceived, tries complete (out-of-order safe)
|-- EventListener/
|   |-- OrderWorkflowListener.php          # Post-transition timestamp setters + domain-event broadcast + cancelled outbox
|   |-- OrderCompletionGuardListener.php   # Blocks complete unless manual mode or verification flag set
|   |-- OrderVerificationCompletionListener.php # Auto-completes on fulfill when verification already received
|   `-- OrderInvoiceListener.php           # Syncs InvoicePaid/Refunded/Cancelled/FailedEvent into Order (pay/refund transitions)
|-- Exception/
|   |-- OrderInvalidTransitionException.php
|   |-- SpecificationNotFoundException.php
|-- Repository/
|   |-- OrderItemRepository.php
|   |-- OrderRepository.php
|-- Service/
|   |-- Catalog/CatalogResolverInterface.php + CatalogItem.php # Trade-owned port/DTO
|   |-- StoreContextResolverInterface.php  # Port; implemented by Store (X-Store-Code header)
|   |-- OrderService.php                   # Order creation + price pipeline (no Store import)
|   |-- OrderServiceInterface.php          # calculatePrices/createOrder/refund/fulfill/createPayment/refundPayment/cancel
|   |-- OrderItemService.php               # Order-item CRUD service
|   |-- TradeOutboxService.php             # record(topic, aggregateType, aggregateId, payload)
|   |-- Pricing/
|       |-- PriceCalculatorInterface.php   # Plugin contract
|       |-- PriceCalculationContext.php    # Input/output DTO (storeCode)
|       |-- PriceCalculationResult.php     # Result DTO
|       |-- BasePriceCalculator.php        # Resolves via CatalogResolver (Store visibility enforced in Store)
|       |-- QuantityCalculator.php         # Computes price = unitPrice * quantity
|       |-- TotalAggregator.php            # Establishes subtotal (priority 55)

 src/Store/
 |-- Entity/Product.php  # trade_product, nullable store ManyToOne Store
 |-- Entity/Specification.php # trade_specification, ManyToOne Store\Product
 |-- Repository/ProductRepository.php, SpecificationRepository.php
 |-- Service/ProductService.php, SpecificationService.php
 |-- Service/Catalog/StoreCatalogResolver.php # Trade CatalogResolverInterface impl (Store visibility)
 |-- Controller/App/ProductController.php, SpecificationController.php # DqlExpression row-scope
 |-- Controller/Manage/ProductController.php, SpecificationController.php, SpecificationAllController.php
```

---

## 3. Entity Relationships

```
Store\Product (status: active/inactive, isDeleted: bool, metadata: JSON, store: ?Store)
  |
  +-- 1:N -> Store\Specification (cascade: persist)

Store\Specification (name, price: int cents, status, sort, isDeleted)
  |  inherits Product.store visibility
  +-- (no FK) -> Trade\OrderItem via scalar specificationUuid + snapshots

Order (uuid, totalAmount: int cents, currency: CNY, status: state machine, notes, metadata _store)
  |
  +-- M:1 -> User
  +-- 1:N -> OrderItem (cascade: persist)

OrderItem (uuid, quantity, unitPrice: cents, price: cents, cost, profit, specificationUuid, specificationTitle, specSnapshot/productSnapshot: JSON)
  |
  +-- M:1 -> Order
  +-- (scalar) specificationUuid (indexed, no FK)
```

---

## 4. Entity Design Details

### 4.1 Product

- UUID v4 for external reference
- `status`: `active` or `inactive`
- `isDeleted`: soft delete flag
- `metadata`: JSON extensible field

### 4.2 Specification

- Belongs to a Product
- `price` in cents (integer, converted to/from decimal at API boundary)
- `status`: active/inactive
- `sort`: manual ordering
- `isDeleted`: soft delete flag

### 4.3 Order

- UUID v4
- `totalAmount` in cents
- `currency`: default `CNY` (Store-scoped orders inherit the Store currency; a mismatched request currency is rejected)
- `status`: state machine marking field (draft/pending/confirmed/paid/fulfilled/completed/cancelled/refunded)
- `paidAt`, `fulfilledAt`, `completedAt`, `cancelledAt`, `refundedAt`: set by `OrderWorkflowListener` on the matching transition (`paidAt` may also be set from the invoice's `paidAt` by `OrderInvoiceListener`; `refundedAt` from the invoice's `refundedAt`)
- `paymentMethod`: gateway name snapshot (e.g. `mock`, `wallet`, `wechat`, `liansheng_point`), synced from the paid invoice
- `invoiceId`/`invoiceNo`: linked Payment invoice `uuid`/`outTradeNo`; `paymentStatus`: synced invoice status snapshot
- `trackingNumber`, `shippingAddress`: set by `fulfill`; `refundReason`: set by legacy wallet `refund`
- `metadata` keys: `_store` (StoreContext snapshot), `_completionMode` (`manual`|`store_verification`), `_storeVerificationReceived` (set by `StoreOrderVerifiedHandler`)
- `items`: cascaded persist

### 4.4 OrderItem

- UUID v4 (unique)
- `specificationUuid`: scalar snapshot of the Specification `uuid` (nullable, indexed, no FK)
- `specificationTitle`: snapshot of the spec name (backfilled from `specSnapshot.name` in `PrePersist` when null)
- `unitPrice`: snapshot of specification's price at order time (cents)
- `price`: `unitPrice * quantity`, recomputed in `#[ORM\PrePersist]`
- `cost`, `profit`: for margin tracking (`setCost()` derives `profit = price - cost`)
- `specSnapshot` (`{id, uuid, name, productId}`), `productSnapshot` (`{id, uuid, name}`): JSON snapshots captured by `BasePriceCalculator` for historical record
- `metadata`: JSON extensible field

---

## 5. Price Calculation Pipeline

### 5.1 Contract

```php
interface PriceCalculatorInterface
{
    public function calculate(PriceCalculationContext $context): void;
    public static function getPriority(): int;
}
```

### 5.2 Calculator Chain (Priority Order)

| Priority | Calculator | Module | Responsibility |
|----------|-----------|--------|----------------|
| -100 | `BasePriceCalculator` | Trade | Resolve Specification entity, validate active/not-deleted, extract unit price, capture snapshots |
| 50 | `QuantityCalculator` | Trade | Compute `price = unitPrice * quantity` for each item |
| **55** | **`TotalAggregator`** | **Trade** | **Establish the subtotal before promotion evaluation** |
| **60** | **`PromotionCalculator`** | **Promotion** | **DSL eval → match → apply (max 20 iterations, applied-ID tracking, exclusive/lock-item/best-price conflict modes)** |

External modules (e.g., `Promotion`, future `Coupon`) hook into the pipeline by implementing `PriceCalculatorInterface` and tagging with `#[AutoconfigureTag('trade.price_calculator')]`. Trade has zero awareness of these modules.

### 5.3 Pipeline Execution

```
OrderService::calculatePrices($items, $currency, $storeCode = null, $meta = [])
  -> Create PriceCalculationContext with items, currency, user, storeCode, meta
  -> Collect all PriceCalculatorInterface implementations (auto-tagged)
  -> Sort by getPriority() ascending
  -> Execute each in sequence on PriceCalculationContext
     (BasePriceCalculator → QuantityCalculator → TotalAggregator → PromotionCalculator)
  -> Return PriceCalculationResult (items, totalAmount, currency, meta)
```

### 5.4 DTOs

```php
class PriceCalculationContext
{
    public array $inputItems;     // Raw input from request
    public array $items;          // Mutated by calculators
    public int $totalAmount;      // Final total in cents
    public string $currency;      // e.g., 'CNY'
    public array $meta = [];      // Bidirectional opaque channel for calculators
    public ?object $user = null;  // Current user (for member-level conditions)
    public ?string $storeCode = null; // Multi-store routing
}

class PriceCalculationResult
{
    public int $totalAmount;
    public string $currency;
    public array $items;          // Calculated order items
    public array $meta;           // From context.meta (carries promotion/coupon results)
}
```

### 5.5 `meta` Channel Contract

`meta` is an opaque array that Trade never inspects. Calculators read from it as input
and write to it as output. The contract is:

| Direction | Example | Set By |
|-----------|---------|--------|
| Client → Calculators | `{coupon: {code: "ABC123"}}` | Request body → `calculatePrices($items, $currency, $storeCode, $meta)` |
| Calculators → Client | `{promotion: {inner: [...], outer: {...}}}` | `PromotionCalculator` writes to `context.meta['promotion']` |
| Any key can coexist | `{promotion: {...}, coupon: {...}, existing: "..."}` | Multiple calculators |

**Guarantees**:
- Trade never reads or mutates `meta` content — it passes through unchanged.
- `PriceCalculationResult::fromContext()` copies `context.meta` verbatim into the result.
- New modules (e.g., Coupon) follow the same pattern: implement `PriceCalculatorInterface`,
  read from `context.meta['coupon']`, write to `context.meta['coupon']`.

### 5.6 Registration

Calculators are auto-discovered via `#[AutoconfigureTag('trade.price_calculator')]` on each
calculator class and injected into `OrderService` via `#[AutowireIterator('trade.price_calculator')]`
(sorted by `getPriority()` ascending at runtime):

```php
// Each calculator self-tags, e.g. BasePriceCalculator:
#[AutoconfigureTag('trade.price_calculator')]
class BasePriceCalculator implements PriceCalculatorInterface { /* ... */ }
```

New calculators can be added by implementing the interface -- no other code changes needed.

---

## 6. Order State Machine

### 6.1 Configuration

**File**: `config/packages/workflow.yaml` — Trade owns the order lifecycle. Store does not add
Trade places or transitions. Store verification is a Trade-owned guard on `complete`.

```yaml
framework:
  workflows:
    order:
      type: state_machine
      marking_store: { type: method, property: status }
      places:
        - draft
        - pending
        - confirmed
        - paid
        - fulfilled
        - completed
        - cancelled
        - refunded
      transitions:
        submit:   draft -> pending
        confirm:  pending -> confirmed
        pay:      confirmed -> paid
        fulfill:  paid -> fulfilled
        complete: fulfilled -> completed  # guard: _completionMode == store_verification requires Store verification
        cancel:   [draft, pending, confirmed] -> cancelled
        refund:   paid -> refunded
```

Guards live in `Trade/EventListener/OrderCompletionGuardListener` (reads `Order.metadata._completionMode`).
Plain manual orders and legacy orders without `_completionMode` remain permissive.

### 6.2 Valid Transitions

| From | To | Transition Name | When |
|------|-----|----------------|------|
| draft | pending | `submit` | always |
| pending | confirmed | `confirm` | |
| confirmed | paid | `pay` | |
| paid | fulfilled | `fulfill` | |
| fulfilled | completed | `complete` | `manual` always; `store_verification` only via Store verification fact |
| draft/pending/confirmed | cancelled | `cancel` | |
| paid | refunded | `refund` | |

### 6.3 Workflow Listener

```php
class OrderWorkflowListener
{
    // On cancel/pay/fulfill/refund/complete -> set timestamps per transition
    //   (paidAt/fulfilledAt/refundedAt only when null; cancelledAt/completedAt always)
    // On pay/fulfill/complete/cancel/refund -> dispatch OrderPaid/Fulfilled/Completed/Cancelled/RefundedEvent
    // On cancel of a store-scoped order -> record trade.order.cancelled.v1 in the outbox
    // On complete -> set completedAt + dispatch OrderCompletedEvent
}
```

Related listeners (same `workflow.order` events):

- `OrderCompletionGuardListener` (`workflow.order.guard.complete`): blocks `complete` when
  `metadata._completionMode === 'store_verification'` unless the transient
  `completingFromStoreVerification` flag is set. Manual/legacy orders without
  `_completionMode` remain permissive. There are no `store_accepted`/`rejected` places.
- `OrderVerificationCompletionListener` (`workflow.order.completed.fulfill`): if the order is
  `store_verification` mode and `_storeVerificationReceived` is already true (verification
  arrived out of order, before `fulfill`), applies `complete` immediately.
- `OrderInvoiceListener` (sync `InvoicePaid/Refunded/Cancelled/FailedEvent`): on paid, verifies
  invoice amount/currency match, syncs `invoiceId`/`invoiceNo`/`paymentStatus`/`paymentMethod`/
  `paidAt`, and applies the `pay` transition; on refunded, syncs status and applies `refund`;
  on cancelled/failed, only syncs `paymentStatus`.
- `StoreOrderVerifiedHandler` (async `StoreOrderVerifiedMessage`, i.e. `store.order.verified.v1`
  dispatched by the Store outbox): ignores unknown orders, store-uuid mismatches, and
  non-`store_verification` orders; otherwise sets `_storeVerificationReceived = true` and
  applies `complete` when enabled — if the order is not yet `fulfilled`, the flag persists
  and `OrderVerificationCompletionListener` completes it after `fulfill`.

---

## 7. Order Creation Flow

```
POST /api/v1/manage/orders (or POST /api/v1/app/orders)
  Body: {items: [{specificationId: N|"uuid", quantity: N}, ...], currency: "CNY", notes: "...", metadata: {...}, meta: {coupon: {...}}}
  + optional X-Store-Code header (resolved by Store into StoreContext; request currency must match Store currency)
  |
  v
OrderService::calculatePrices($items, $currency, $storeCode, $meta)
  -> Create PriceCalculationContext(items, currency)
  -> Set context.user, context.storeCode, context.meta = $meta
   -> Price Calculation Pipeline (Base → Quantity → TotalAggregator [subtotal] → Promotion)
   -> Returns PriceCalculationResult (items, totalAmount, currency, meta)
  |
  v
OrderService::createOrder($calculatedItems, $user, $totalAmount, $currency, $notes, $metadata, $storeContext)
  -> Within transaction:
     -> Create Order entity (totalAmount, currency, notes, metadata)
     -> When $storeContext !== null: snapshot metadata._store + metadata._completionMode
        (manual|store_verification), apply submit (draft -> pending), record trade.order.created.v1 outbox
        with items [{lineId (= item uuid), catalogReference (= specificationUuid), quantity, unitPrice,
        lineAmount, snapshot: {specification, product}}, ...], delivery, placedAt
     -> For each calculated item: create OrderItem
        -> Snapshot spec + product data
        -> Auto-calculate price = unitPrice * quantity (PrePersist)
     -> Persist + flush
  -> Returns Order entity
```

### 7.1 Quote Flow (Order Preview)

```
POST /api/v1/app/orders/quote
  Body: {items: [{specificationId: N, quantity: N}, ...], meta: {coupon: {code: "ABC"}}}
  |
  v
OrderService::calculatePrices($items, $currency, $storeCode, $meta)
  -> Returns PriceCalculationResult (items, totalAmount, currency, meta)
  -> No Order is persisted — pure pricing preview
  
Response:
{
  data: {
    items: [{specificationId: 1, unitPrice: 10000, price: 10000, ...}],
    totalAmount: 8000,
    currency: "CNY",
    meta: {
      promotion: { inner: [{promotionId: 1, promotionName: "满减", ...}] }
    }
  }
}
```

---

## 8. API Endpoints

### 8.1 Manage (Admin, ROLE_ADMIN)

Product/Specification endpoints live in the Store bundle (`src/Store/Controller/Manage/`);
Trade Manage owns orders and order items only.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/orders` | List orders |
| GET | `/api/v1/manage/orders/{id}` | Order detail |
| POST | `/api/v1/manage/orders` | Create order (custom logic; accepts `user` id) |
| **POST** | **`/api/v1/manage/orders/quote`** | **Calculate prices without creating order** |
| PUT | `/api/v1/manage/orders/{id}` | Update draft order only |
| DELETE | `/api/v1/manage/orders/{id}` | Delete draft order only |
| GET | `/api/v1/manage/orders/{id}/items` | View order items |
| POST | `/api/v1/manage/orders/{id}/fulfill` | Fulfill (paid → fulfilled) with tracking info |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund via linked invoice when present, else wallet transfer (paid → refunded) |
| GET | `/api/v1/manage/orders/todo` | Orders with available transitions |
| GET | `/api/v1/manage/orders/{id}/transitions` | Enabled transitions |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition (cancel also cancels linked invoice) |
| PUT | `/api/v1/manage/orders/{id}/status-reset` | Admin reset marking |
| GET/POST/PUT/DELETE | `/api/v1/manage/order-items[/{id}]` | Order-item CRUD (`OrderItemController`) |

### 8.2 App (Authenticated, ROLE_USER, own orders only)

Product/Specification browsing lives in the Store bundle (`src/Store/Controller/App/`).

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/orders` | List current user's orders |
| GET | `/api/v1/app/orders/{id}` | Order detail |
| POST | `/api/v1/app/orders` | Create order |
| **POST** | **`/api/v1/app/orders/quote`** | **Calculate prices without creating order** |
| GET | `/api/v1/app/orders/{id}/items` | View order items |
| POST | `/api/v1/app/orders/{id}/submit` | Submit draft → pending |
| POST | `/api/v1/app/orders/{id}/confirm` | Confirm pending → confirmed |
| POST | `/api/v1/app/orders/{id}/payment` | Start payment via invoice (`{payment, ...options}`) |
| POST | `/api/v1/app/orders/{id}/refund` | Refund via linked invoice when present, else wallet transfer |
| POST | `/api/v1/app/orders/{id}/cancel` | Cancel own order (workflow `cancel` + linked invoice cancel) |

---

## 9. Order Controller Constraints

### 9.1 Update Constraint

Orders can only be updated in `draft` status. Attempting to update non-draft orders should be rejected.

### 9.2 Delete Constraint

Orders can only be deleted in `draft` status. Other states require cancellation first.

### 9.3 Workflow Operations

- `todo`: Lists all orders that have at least one enabled transition
- `transitions`: Returns available transitions for a specific order
- `do/{transition}`: Executes the named transition within a transaction, optionally accepting data to update the entity before transition

### 9.4 Payment

There is no `POST /manage/orders/{id}/pay` endpoint. Payment is started by the customer via
`POST /app/orders/{id}/payment` with `{payment, ...options}` (`payment` defaults to `mock`),
which calls `OrderService::createPayment()`: it reuses the pending linked invoice when one
exists, otherwise creates one via `InvoiceService::createInvoice()` (`sourceType: trade_order`,
`scene: order`), syncs `invoiceId`/`invoiceNo`/`paymentStatus`, and delegates to
`InvoiceService::pay()`. The `confirmed → paid` transition is applied asynchronously by
`OrderInvoiceListener` on `InvoicePaidEvent` (after amount/currency verification), which also
syncs `paymentMethod` and `paidAt`. There is no `OrderService::pay()` method and
`TransferService` is never touched on the pay path.

### 9.5 Fulfillment

- `POST /manage/orders/{id}/fulfill` with `{trackingNumber, shippingAddress}`
- Validates order is in `paid` status
- Sets `fulfilledAt`, `trackingNumber`, `shippingAddress`
- Applies `fulfill` transition

### 9.6 Refund

- `POST /manage/orders/{id}/refund` and `POST /app/orders/{id}/refund` with `{reason, ...}`
- Validates the order can take the `refund` transition (i.e. status is `paid`; `fulfilled`/`completed` cannot directly refund)
- When a linked invoice exists (`invoiceId !== null`), delegates to `OrderService::refundPayment()`
  (`InvoiceService::refund()`); the `paid → refunded` transition is then applied by
  `OrderInvoiceListener` on `InvoiceRefundedEvent`
- Otherwise (legacy wallet path) requires `{systemWalletId}`: `OrderService::refund()` transfers
  from the system wallet back to the user's wallet via `TransferService`, sets `refundedAt`/
  `refundReason`, and the controller applies the `refund` transition in the same transaction

### 9.7 User Cancel

- `POST /app/orders/{id}/cancel` -- authenticated user cancels own order (ownership verified)
- Allowed only when the workflow allows `cancel` (i.e. status is `draft`, `pending`, or `confirmed`)
- Runs in a transaction via the `cancel` workflow transition (not a direct status update) and
  cancels the linked invoice first via `OrderService::cancel()` (`InvoiceService::cancel()`)

### 9.8 View Items

- `GET /manage/orders/{id}/items` -- admin view
- `GET /app/orders/{id}/items` -- user view (ownership verified)

---

## 10. Money Handling Contract

| Aspect | Rule |
|--------|------|
| Storage | `bigint` (cents) in database |
| PHP type | `int` for amounts |
| API input | Decimal string/number |
| API output | Decimal string/number |
| Conversion on write | `* 100` (via `@transform` expression or service) |
| Conversion on read | `/ 100` |
| Points currency | `LIANSHENG_POINT` invoices carry whole point units (no cents conversion; see Payment bundle `liansheng_point` gateway). Trade `totalAmount` stays an integer throughout and Store currency is passed through verbatim |

---

## 11. Database Migrations

- `Version20260624223701`: adds `invoice_id`/`invoice_no`/`payment_status` to `trade_order`
  (plus datetime-type alignment on trade tables).
- `Version20260725020000`: creates `trade_outbox_message` (`event_id` unique, `topic`,
  `aggregate_type`, `aggregate_id`, `payload`, `occurred_at`/`available_at`/`published_at`,
  `attempts`, `last_error`).
- `Version20260725040000`: aligns outbox datetime mappings with Doctrine mappings.
- `Version20260725050000`: widens `trade_order.status` to `VARCHAR(40)`.
- `Version20260903000000` + `Version20260903000001`: OrderItem UUID migration — backfills
  `specification_uuid` from the old `specification_id` FK, drops the FK/index/column
  (irreversible), adds `idx_trade_order_item_spec_uuid`.
- `Version20260903000005`: `trade_order.currency` definition (default `CNY`).
- `Version20260911000000`: adds `type` discriminator (`normal` vs `coupon`) to `trade_product`
  (Store-owned entity, shared table).

Earlier bootstrapping migrations (`Version20250620000000` creating the four trade tables;
`Version20250621000000` adding `paid_at`/`refunded_at`/`fulfilled_at`/`payment_method`/
`tracking_number`/`shipping_address`/`refund_reason`) predate the `Version2026*` series above.

---

## 12. Testing

| Suite | Tests |
|-------|-------|
| `tests/UnitTest/Trade/Entity/` | Order, OrderItem (+ legacy Product/Specification) unit tests |
| `tests/UnitTest/Trade/Service/` | OrderService create order, OrderService payments (invoice paths) |
| `tests/UnitTest/Trade/Pricing/` | Pipeline pricing tests |
| `tests/UnitTest/Trade/EventListener/` | OrderInvoiceListener, OrderWorkflowListener |
| `tests/UnitTest/Trade/Controller/` | App + Manage OrderController tests |
| `tests/UnitTest/Promotion/Service/` | PromotionCalculator pipeline tests (real pipeline coverage) |
