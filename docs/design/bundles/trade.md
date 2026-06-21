# Trade Bundle Design

> The Trade bundle (`src/Trade/`) is the e-commerce module. It implements products, specifications,
> orders, order items with a pluggable price calculation pipeline and Symfony Workflow-based
> order state machine.

---

## 1. Overview

Trade provides a complete order management system:

- **Products** with multiple **Specifications** (SKU-like variants with pricing)
- **Orders** with a state machine lifecycle (draft -> completed)
- **Order Items** with price snapshots for historical accuracy
- **Price Calculation Pipeline**: pluggable calculators with priority ordering
- **Soft Deletes**: products and specifications use `isDeleted` flag
- **UUID v4**: external identifiers for orders and items

### 1.1 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Product` | `trade_product` | Sellable product with name, description, status |
| `Specification` | `trade_specification` | Product variant (name, price in cents, status) |
| `Order` | `trade_order` | Purchase order with state machine, total, currency |
| `OrderItem` | `trade_order_item` | Line item with snapshot, quantity, unit price |

---

## 2. File Structure

```
src/Trade/
|-- Controller/
|   |-- App/
|   |   |-- OrderController.php           # Public read: list my orders
|   |   |-- ProductController.php          # Public read: list products
|   |-- Manage/
|       |-- OrderController.php            # CRUD + workflow + price calculation
|       |-- ProductController.php          # CRUD
|       |-- SpecificationController.php    # CRUD
|-- Entity/
|   |-- Order.php
|   |-- OrderItem.php
|   |-- Product.php
|   |-- Specification.php
|-- EventListener/
|   |-- OrderWorkflowListener.php          # Post-transition timestamp setters
|-- Exception/
|   |-- OrderInvalidTransitionException.php
|   |-- SpecificationNotFoundException.php
|-- Repository/
|   |-- OrderItemRepository.php
|   |-- OrderRepository.php
|   |-- ProductRepository.php
|   |-- SpecificationRepository.php
|-- Service/
|   |-- OrderItemService.php
|   |-- OrderService.php                   # Order creation + price pipeline
|   |-- ProductService.php
|   |-- SpecificationService.php
|   |-- Pricing/
|       |-- PriceCalculatorInterface.php   # Plugin contract
|       |-- PriceCalculationContext.php    # Input/output DTO
|       |-- PriceCalculationResult.php     # Result DTO
|       |-- BasePriceCalculator.php        # Resolves specs, extracts unit price
|       |-- QuantityCalculator.php         # Computes price = unitPrice * quantity
|       |-- TotalAggregator.php            # Sums all items into totalAmount
```

---

## 3. Entity Relationships

```
Product (status: active/inactive, isDeleted: bool, metadata: JSON)
  |
  +-- 1:N -> Specification (cascade: persist)

Specification (name, price: int cents, status, sort, isDeleted)
  |
  +-- 1:N -> OrderItem

Order (uuid, totalAmount: int cents, currency: CNY, status: state machine, notes, metadata)
  |
  +-- M:1 -> User
  +-- 1:N -> OrderItem (cascade: persist)

OrderItem (uuid, quantity, unitPrice: cents, price: cents, cost, profit, snapshots: JSON)
  |
  +-- M:1 -> Order
  +-- M:1 -> Specification
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
- `currency`: default `CNY`
- `status`: state machine marking field (draft/pending/confirmed/paid/fulfilled/completed/cancelled/refunded)
- `cancelledAt`, `completedAt`: set by `OrderWorkflowListener`
- `items`: cascaded persist

### 4.4 OrderItem

- UUID v4
- `unitPrice`: snapshot of specification's price at order time (cents)
- `price`: `unitPrice * quantity`, auto-calculated in `#[ORM\PrePersist]`
- `cost`, `profit`: for margin tracking
- `specSnapshot`, `productSnapshot`: JSON snapshots captured at creation for historical record

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

| Priority | Calculator | Responsibility |
|----------|-----------|----------------|
| -100 | `BasePriceCalculator` | Resolve Specification entity, validate active/not-deleted, extract unit price, capture snapshots |
| 50 | `QuantityCalculator` | Compute `price = unitPrice * quantity` for each item |
| 100 | `TotalAggregator` | Sum all item prices into `context.totalAmount` |

### 5.3 Pipeline Execution

```
OrderService::calculatePrices($items, $currency)
  -> Collect all PriceCalculatorInterface implementations (auto-tagged)
  -> Sort by getPriority() ascending
  -> Execute each in sequence on PriceCalculationContext
  -> Return PriceCalculationResult (items, totalAmount, currency)
```

### 5.4 DTOs

```php
class PriceCalculationContext
{
    public array $inputItems;     // Raw input from request
    public array $items;          // Mutated by calculators
    public int $totalAmount;      // Final total in cents
    public string $currency;      // e.g., 'CNY'
    public array $meta;           // Extensible metadata
}

class PriceCalculationResult
{
    public int $totalAmount;
    public string $currency;
    public array $items;          // Calculated order items
}
```

### 5.5 Registration

Calculators are auto-discovered and tagged via `config/services.yaml`:

```yaml
services:
  App\Trade\Service\Pricing\:
    resource: '../src/Trade/Service/Pricing/'
    tags: ['trade.price_calculator']
```

New calculators can be added by implementing the interface -- no other code changes needed.

---

## 6. Order State Machine

### 6.1 Configuration

**File**: `config/packages/workflow.yaml`

```yaml
framework:
  workflows:
    order:
      type: state_machine
      marking_store:
        type: method
        property: status
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
        complete: fulfilled -> completed
        cancel:   [draft, pending, confirmed] -> cancelled
        refund:   completed -> refunded
```

### 6.2 Valid Transitions

| From | To | Transition Name |
|------|-----|----------------|
| draft | pending | `submit` |
| pending | confirmed | `confirm` |
| confirmed | paid | `pay` |
| paid | fulfilled | `fulfill` |
| fulfilled | completed | `complete` |
| draft | cancelled | `cancel` |
| pending | cancelled | `cancel` |
| confirmed | cancelled | `cancel` |
| completed | refunded | `refund` |

### 6.3 Workflow Listener

```php
class OrderWorkflowListener
{
    // On cancel transition -> set cancelledAt = now
    // On complete transition -> set completedAt = now
}
```

---

## 7. Order Creation Flow

```
POST /api/v1/manage/orders
  Body: {items: [{specification: {id: N}, quantity: N}, ...], currency: "CNY", notes: "..."}
  |
  v
OrderService::calculatePrices($items, $currency)
  -> Price Calculation Pipeline
  -> Returns PriceCalculationResult
  |
  v
OrderService::createOrder($calculatedItems, $user, $totalAmount, $currency, $notes)
  -> Within transaction:
     -> Create Order entity
     -> For each calculated item: create OrderItem
        -> Snapshot spec + product data
        -> Auto-calculate price = unitPrice * quantity (PrePersist)
     -> Persist + flush
  -> Returns Order entity
```

---

## 8. API Endpoints

### 8.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/products` | List products |
| GET | `/api/v1/manage/products/{id}` | Product detail |
| POST | `/api/v1/manage/products` | Create product |
| PUT | `/api/v1/manage/products/{id}` | Update product |
| DELETE | `/api/v1/manage/products/{id}` | Delete product |
| GET | `/api/v1/manage/specifications` | List specs |
| POST | `/api/v1/manage/specifications` | Create spec |
| PUT | `/api/v1/manage/specifications/{id}` | Update spec |
| DELETE | `/api/v1/manage/specifications/{id}` | Delete spec |
| GET | `/api/v1/manage/orders` | List orders |
| GET | `/api/v1/manage/orders/{id}` | Order detail |
| POST | `/api/v1/manage/orders` | Create order (custom logic) |
| PUT | `/api/v1/manage/orders/{id}` | Update draft order only |
| DELETE | `/api/v1/manage/orders/{id}` | Delete draft order only |
| GET | `/api/v1/manage/orders/{id}/items` | View order items |
| POST | `/api/v1/manage/orders/{id}/pay` | Pay with wallet deduction |
| POST | `/api/v1/manage/orders/{id}/fulfill` | Fulfill with tracking info |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund with wallet credit |
| GET | `/api/v1/manage/orders/todo` | Orders with available transitions |
| GET | `/api/v1/manage/orders/{id}/transitions` | Enabled transitions |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition |
| PUT | `/api/v1/manage/orders/{id}/status-reset` | Admin reset marking |

### 8.2 App (Public, Authenticated)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active, non-deleted products |
| GET | `/api/v1/app/products/{id}` | Product detail |
| GET | `/api/v1/app/orders` | List current user's orders |
| GET | `/api/v1/app/orders/{id}` | Order detail |
| POST | `/api/v1/app/orders` | Create order |
| GET | `/api/v1/app/orders/{id}/items` | View order items |
| POST | `/api/v1/app/orders/{id}/cancel` | Cancel own order |

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

### 9.4 Payment (Pay)

- `POST /manage/orders/{id}/pay` with `{systemWalletId, paymentMethod}`
- Validates order is in `confirmed` status
- Deducts from user's wallet, credits to system wallet via `TransferService`
- Sets `paidAt`, `paymentMethod`, applies `pay` transition

### 9.5 Fulfillment

- `POST /manage/orders/{id}/fulfill` with `{trackingNumber, shippingAddress}`
- Validates order is in `paid` status
- Sets `fulfilledAt`, `trackingNumber`, `shippingAddress`
- Applies `fulfill` transition

### 9.6 Refund

- `POST /manage/orders/{id}/refund` with `{systemWalletId, reason}`
- Validates order is in `completed` status
- Transfers from system wallet back to user's wallet via `TransferService`
- Sets `refundedAt`, `refundReason`, applies `refund` transition

### 9.7 User Cancel

- `POST /app/orders/{id}/cancel` -- authenticated user cancels own order
- Allowed only when status is `draft`, `pending`, or `confirmed`
- Sets status to `cancelled` (not via workflow, direct update)

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

---

## 11. Database Migrations

**Version**: `Version20250620000000`

Creates 4 tables: `trade_product`, `trade_specification`, `trade_order`, `trade_order_item`.

**Version**: `Version20250621000000`

Adds columns to `trade_order`: `paid_at`, `refunded_at`, `fulfilled_at`, `payment_method`, `tracking_number`, `shipping_address`, `refund_reason`.

---

## 12. Testing

| Suite | Tests |
|-------|-------|
| `tests/Trade/Entity/` | Product, Order, OrderItem, Specification unit tests |
| `tests/Trade/Service/` | OrderService create order, OrderItem service |
| `tests/Trade/Pricing/` | BasePriceCalculator, QuantityCalculator, TotalAggregator |
| `tests/Trade/Integration/` | Product repository, Order repository integration |
