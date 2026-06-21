# Payment Bundle Design

> Payment module design for invoice-based payment orchestration.
> The first implementation MUST provide a generic payment framework, not provider-specific Fuiou/Huifu code.
> Fuiou and Huifu are referenced only as proven legacy architecture inputs for future gateway adapters.

---

## 1. Scope

### 1.1 Goal

The Payment module provides a unified invoice abstraction for all payment flows:

| Capability | Purpose |
|------------|---------|
| Invoice creation | Represent a payable business document independent of payment provider |
| Gateway dispatch | Route pay/notify/refund actions to a named gateway adapter |
| State management | Enforce legal invoice status transitions |
| Webhook handling | Verify provider callbacks and update invoices idempotently |
| Cross-module events | Notify business modules such as Trade when invoices are paid/refunded |

### 1.2 Non-Goals For First Phase

The first phase MUST NOT include concrete Fuiou/Huifu integration code.

| Excluded | Reason |
|----------|--------|
| Fuiou/Huifu real API clients | Requires provider credentials, SDK/runtime validation, and separate certification |
| Provider account onboarding | Belongs to a later provider submodule phase |
| POS device management | Provider-specific operational module, not core invoice framework |
| Auto withdraw / settlement | Finance and settlement concern, not first-phase payment collection |
| Split settlement / fees | Requires accounting design beyond invoice payment |
| Payment requisition | Outbound payment approval workflow, separate from collection invoices |

### 1.3 Legacy Reference

The legacy `farm-neighbor` implementation provides useful concepts:

| Legacy Concept | Keep | Adaptation |
|----------------|------|------------|
| `Invoice` as central payment document | Yes | Modern PHP 8 entity, int cents, string statuses |
| `outTradeNo` unique system trade number | Yes | Keep as external payment number |
| `transactionId` provider trade id | Yes | Keep nullable until provider confirms |
| `scene` for business scenario | Yes | Use with `sourceType/sourceId` |
| `payment/gateway/tradeType` separation | Yes | Keep for provider routing and reporting |
| `extraData` for provider payload | Yes | Store sanitized payload snapshots only |
| `PayStrategy` `pay/notify/refund` shape | Yes | Replace with tagged `PaymentGatewayInterface` services |
| `InvoiceAdditionalService` scene dispatch | Partially | Replace with Symfony events |
| Dynamic `new $paymentClassName` | No | Use DI registry and explicit service tags |
| Doctrine listener business side effects | No | Use service methods and domain events |
| DBAL direct insert/update | No | Use Doctrine entities through services |
| Gateway code returning `die()` or raw response from service | No | Controller responder owns HTTP response |

---

## 2. Module Boundary

### 2.1 Module Location

```
src/Payment/
|-- Controller/
|   |-- App/InvoiceController.php
|   |-- Manage/InvoiceController.php
|   |-- Webhook/PaymentNotifyController.php
|-- Entity/Invoice.php
|-- Repository/InvoiceRepository.php
|-- Service/InvoiceService.php
|-- Service/InvoiceServiceInterface.php
|-- Service/PaymentGatewayInterface.php
|-- Service/PaymentGatewayRegistry.php
|-- Service/Gateway/MockGateway.php
|-- Service/Gateway/WalletGateway.php
|-- DTO/CreateInvoiceRequest.php
|-- DTO/PaymentResult.php
|-- DTO/PaymentNotifyResult.php
|-- DTO/PaymentRefundResult.php
|-- Event/InvoicePaidEvent.php
|-- Event/InvoiceRefundedEvent.php
|-- Event/InvoiceCancelledEvent.php
|-- Event/InvoiceFailedEvent.php
|-- Exception/InvoiceInvalidTransitionException.php
|-- Exception/PaymentGatewayNotFoundException.php
|-- Exception/PaymentVerificationException.php
|-- Resources/config/services_payment.yaml
```

### 2.2 Dependency Direction

| From | To | Allowed | Rule |
|------|----|---------|------|
| Payment | Core | Yes | BaseService, RestController, serializer, events |
| Payment | Identity | Yes | Optional payer relation or current user lookup |
| Payment | Wallet | Yes | Only for `WalletGateway`; depend on service interfaces |
| Payment | Trade | No | Payment MUST NOT import Trade services or entities |
| Trade | Payment | Yes | Trade consumes `InvoiceServiceInterface` and Payment events |
| Gateway adapters | Payment | Yes | Implement `PaymentGatewayInterface` |

Payment is a generic collection module. Business modules decide how to react to invoice events.

---

## 3. Invoice Domain Model

### 3.1 Entity Contract

**File**: `src/Payment/Entity/Invoice.php`

**Namespace**: `App\Payment\Entity`

**Table**: `payment_invoice`

Invoice is the aggregate root for a payable document. It is not owned by Trade, Wallet, or any gateway provider.

### 3.2 Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | Yes | Internal primary key |
| `uuid` | string(36) | Yes | Public stable identifier |
| `outTradeNo` | string(64) | Yes | Unique system payment number sent to providers |
| `transactionId` | ?string(128) | No | Provider transaction id after payment succeeds |
| `sourceType` | string(50) | Yes | Business source, e.g. `trade_order` |
| `sourceId` | string(64) | Yes | Source id, preferably source UUID |
| `scene` | string(50) | Yes | Business scenario, e.g. `order`, `deposit`, `wallet_topup` |
| `payment` | ?string(50) | No | Gateway adapter name, e.g. `wallet`, `mock` |
| `gateway` | ?string(50) | No | Provider sub-gateway or channel |
| `tradeType` | ?string(50) | No | Provider trade type such as `jsapi`, `h5`, `pos` |
| `status` | string(30) | Yes | Workflow marking |
| `amount` | int | Yes | Payable amount in cents |
| `refundedAmount` | int | Yes | Refunded amount in cents |
| `currency` | string(10) | Yes | ISO-like currency code, default `CNY` |
| `payer` | ?User | No | Optional payer relation |
| `subject` | ?string(255) | No | Provider-visible subject/title |
| `description` | ?string | No | Human-readable details |
| `extraData` | ?array | No | Sanitized provider/business metadata |
| `createdAt` | DateTimeImmutable | Yes | Creation timestamp |
| `paidAt` | ?DateTimeImmutable | No | Payment completion timestamp |
| `cancelledAt` | ?DateTimeImmutable | No | Cancellation timestamp |
| `refundedAt` | ?DateTimeImmutable | No | Full refund timestamp |
| `updatedAt` | ?DateTimeImmutable | No | Last update timestamp |

### 3.3 Status Constants

```php
public const STATUS_PENDING = 'pending';
public const STATUS_PAYING = 'paying';
public const STATUS_PAID = 'paid';
public const STATUS_FAILED = 'failed';
public const STATUS_CANCELLED = 'cancelled';
public const STATUS_PARTIAL_REFUNDED = 'partial_refunded';
public const STATUS_REFUNDED = 'refunded';
```

### 3.4 Scene Constants

```php
public const SCENE_ORDER = 'order';
public const SCENE_DEPOSIT = 'deposit';
public const SCENE_WALLET_TOPUP = 'wallet_topup';
```

Only `SCENE_ORDER` is required in the first phase. Other scene constants exist to keep the framework extensible.

### 3.5 Payment Constants

```php
public const PAYMENT_MOCK = 'mock';
public const PAYMENT_WALLET = 'wallet';
```

Provider constants such as `fuiou`, `fuiou-pos`, `huifu`, and `huifu-pos` are reserved for future adapters. They MUST NOT be used until concrete adapters exist.

### 3.6 Constraints

| Constraint | Requirement |
|------------|-------------|
| `uuid` | Unique |
| `outTradeNo` | Unique |
| `sourceType + sourceId + status` | Indexed |
| `sourceType + sourceId + scene` | Indexed |
| `payment + transactionId` | Indexed, nullable transaction id |
| `amount` | `>= 0` |
| `refundedAmount` | `>= 0`, `<= amount` |

### 3.7 Amount Contract

All invoice amounts MUST be stored as integer cents.

| Direction | Format |
|-----------|--------|
| Entity storage | int cents |
| Service DTO | int cents |
| External API request | decimal string or number accepted, converted in service/controller |
| Provider payload | provider-specific, converted at gateway boundary |

Gateway adapters are responsible for converting cents into provider formats such as yuan decimal strings or fen integers.

---

## 4. Invoice State Machine

### 4.1 Workflow Requirement

Invoice MUST use Symfony Workflow as a `state_machine`.

**File**: `config/packages/workflow.yaml`

```yaml
framework:
    workflows:
        invoice:
            type: 'state_machine'
            marking_store:
                type: 'method'
                property: 'status'
            supports:
                - App\Payment\Entity\Invoice
            initial_marking: pending
            places:
                - pending
                - paying
                - paid
                - failed
                - cancelled
                - partial_refunded
                - refunded
            transitions:
                start_pay:
                    from: pending
                    to: paying
                mark_paid:
                    from: [pending, paying]
                    to: paid
                fail:
                    from: [pending, paying]
                    to: failed
                cancel:
                    from: [pending, paying, failed]
                    to: cancelled
                partial_refund:
                    from: paid
                    to: partial_refunded
                refund:
                    from: [paid, partial_refunded]
                    to: refunded
```

### 4.2 Transition Ownership

| Transition | Called By | When |
|------------|-----------|------|
| `start_pay` | `InvoiceService::pay()` | Gateway payment request starts |
| `mark_paid` | `InvoiceService::markPaid()` | Gateway confirms success or wallet transfer succeeds |
| `fail` | `InvoiceService::markFailed()` | Gateway confirms terminal failure |
| `cancel` | `InvoiceService::cancel()` | User/admin/system cancels unpaid invoice |
| `partial_refund` | `InvoiceService::refund()` | Refund amount is less than remaining paid amount |
| `refund` | `InvoiceService::refund()` | Refund completes the full paid amount |

Controllers and gateway adapters MUST NOT call `$invoice->setStatus()` directly.

### 4.3 Idempotency Rules

| Event | Current Status | Behavior |
|-------|----------------|----------|
| Duplicate paid notify | `paid` | Return success without re-dispatching business event |
| Paid notify after `cancelled` | `cancelled` | Reject by default; future provider-specific patch flow must be explicit |
| Failed notify after `paid` | `paid` | Ignore and log warning |
| Refund notify after `refunded` | `refunded` | Return success without double refunding |
| Partial refund duplicate | `partial_refunded` | Use provider refund id or idempotency key to prevent double counting |

---

## 5. Service Design

### 5.1 InvoiceServiceInterface

**File**: `src/Payment/Service/InvoiceServiceInterface.php`

```php
interface InvoiceServiceInterface extends BaseServiceInterface
{
    public function createInvoice(CreateInvoiceRequest $request): Invoice;

    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult;

    public function handleNotifyResult(PaymentNotifyResult $result): Invoice;

    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice;

    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice;

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice;

    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult;

    public function findBySource(string $sourceType, string $sourceId): array;
}
```

### 5.2 InvoiceService Responsibilities

| Responsibility | Detail |
|----------------|--------|
| Create invoices | Assign `uuid`, `outTradeNo`, source fields, amount, currency, payer |
| Validate amount | Reject negative amount and invalid refund amount |
| Apply workflow transitions | Use `state_machine.invoice`, never direct status writes |
| Call gateway registry | Resolve named gateway for pay/refund |
| Handle notify result | Find invoice by `outTradeNo` or transaction id and update safely |
| Persist provider snapshots | Store sanitized notify/refund data in `extraData` |
| Dispatch events | Emit invoice domain events after successful state changes |
| Maintain idempotency | Treat duplicate terminal callbacks as successful no-ops |

### 5.3 DTOs

#### CreateInvoiceRequest

```php
final readonly class CreateInvoiceRequest
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $scene,
        public int $amount,
        public string $currency = 'CNY',
        public ?User $payer = null,
        public ?string $subject = null,
        public ?string $description = null,
        public array $extraData = [],
    ) {}
}
```

#### PaymentResult

```php
final readonly class PaymentResult
{
    public function __construct(
        public Invoice $invoice,
        public string $status,
        public ?string $payUrl = null,
        public ?string $qrCode = null,
        public ?array $payload = null,
        public ?string $message = null,
    ) {}
}
```

#### PaymentNotifyResult

```php
final readonly class PaymentNotifyResult
{
    public function __construct(
        public string $payment,
        public string $outTradeNo,
        public string $status,
        public int $amount,
        public string $currency = 'CNY',
        public ?string $transactionId = null,
        public ?\DateTimeImmutable $paidAt = null,
        public array $rawData = [],
        public string $responseBody = 'SUCCESS',
    ) {}
}
```

#### PaymentRefundResult

```php
final readonly class PaymentRefundResult
{
    public function __construct(
        public Invoice $invoice,
        public int $amount,
        public string $status,
        public ?string $refundId = null,
        public array $rawData = [],
    ) {}
}
```

---

## 6. Gateway Framework

### 6.1 Gateway Interface

**File**: `src/Payment/Service/PaymentGatewayInterface.php`

```php
interface PaymentGatewayInterface
{
    public static function getName(): string;

    public function pay(Invoice $invoice, array $options = []): PaymentResult;

    public function notify(Request $request): PaymentNotifyResult;

    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult;

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response;
}
```

### 6.2 Gateway Registry

Gateway implementations MUST be registered through a tagged iterator.

```yaml
services:
    App\Payment\Service\Gateway\:
        resource: '../src/Payment/Service/Gateway/'
        tags: ['payment.gateway']
```

```php
final class PaymentGatewayRegistry
{
    /** @param iterable<PaymentGatewayInterface> $gateways */
    public function __construct(iterable $gateways) {}

    public function get(string $name): PaymentGatewayInterface {}

    public function has(string $name): bool {}

    public function names(): array {}
}
```

### 6.3 First-Phase Gateways

| Gateway | Purpose | Required |
|---------|---------|----------|
| `mock` | Deterministic test/development gateway | Yes |
| `wallet` | Internal wallet balance payment | Yes if Wallet module remains payment-capable |

### 6.4 Future Provider Gateways

Future provider adapters MUST live under Payment gateway namespaces and MUST NOT be standalone legacy bundles.

```
src/Payment/Service/Gateway/Fuiou/
|-- FuiouGateway.php
|-- FuiouPosGateway.php
|-- FuiouClient.php
|-- FuiouSigner.php
|-- FuiouNotifyParser.php

src/Payment/Service/Gateway/Huifu/
|-- HuifuGateway.php
|-- HuifuPosGateway.php
|-- HuifuClient.php
|-- HuifuSigner.php
|-- HuifuNotifyParser.php
```

Provider-specific account, POS, and withdraw features MUST be implemented later as optional provider submodules, not as first-phase invoice framework.

---

## 7. Webhook Design

### 7.1 Routes

Webhook endpoints are intentionally outside `/api/v1` business routes because they are called by providers.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/payment/notify/{payment}` | Generic provider payment notification |
| POST | `/api/payment/refund-notify/{payment}` | Optional provider refund notification |

### 7.2 Controller Contract

**File**: `src/Payment/Controller/Webhook/PaymentNotifyController.php`

Controller responsibilities:

| Step | Action |
|------|--------|
| 1 | Resolve gateway by `{payment}` |
| 2 | Call `$gateway->notify($request)` |
| 3 | Pass result to `InvoiceService::handleNotifyResult()` |
| 4 | Return `$gateway->getNotifySuccessResponse($result)` |
| 5 | Convert verification errors to provider-compatible failure response |

The controller MUST NOT update `Order`, `Wallet`, or other business module entities.

### 7.3 Notify Verification

Every gateway notify parser MUST verify:

| Check | Requirement |
|-------|-------------|
| Signature | Required for external gateways |
| Merchant id | Must match configured merchant |
| Amount | Must equal invoice amount for pay callbacks |
| Currency | Must match invoice currency when provider sends it |
| Trade number | Must map to invoice `outTradeNo` |
| Transaction id | Must be stored when success is confirmed |

---

## 8. Event Contract

### 8.1 Events

| Event | Emitted By | When |
|-------|------------|------|
| `InvoicePaidEvent` | `InvoiceService::markPaid()` | Invoice first reaches `paid` |
| `InvoiceRefundedEvent` | `InvoiceService::refund()` or refund notify | Invoice reaches `refunded` or `partial_refunded` |
| `InvoiceCancelledEvent` | `InvoiceService::cancel()` | Invoice reaches `cancelled` |
| `InvoiceFailedEvent` | `InvoiceService::markFailed()` | Invoice reaches `failed` |

### 8.2 Event Payload

```php
final class InvoicePaidEvent
{
    public function __construct(private readonly Invoice $invoice) {}

    public function getInvoice(): Invoice {}
}
```

Events MAY include the relevant DTO result if the consumer needs provider details.

### 8.3 Business Module Reaction

Business modules react to invoice events. Payment MUST NOT call Trade directly.

| Consumer | Event | Action |
|----------|-------|--------|
| Trade | `InvoicePaidEvent` | Apply order `pay` transition after validating amount and source |
| Trade | `InvoiceRefundedEvent` | Apply order `refund` transition when full refund is confirmed |
| Trade | `InvoiceCancelledEvent` | Optionally cancel unpaid order invoice reference |

---

## 9. Trade Integration

### 9.1 Source Mapping

Trade orders use these invoice source values:

| Field | Value |
|-------|-------|
| `sourceType` | `trade_order` |
| `sourceId` | `Order::getUuid()` preferred |
| `scene` | `order` |
| `amount` | `Order::getTotalAmount()` |
| `currency` | `Order::getCurrency()` |

### 9.2 Order Fields

Trade `Order` SHOULD store lightweight invoice references only:

| Field | Type | Purpose |
|-------|------|---------|
| `invoiceId` | ?string | Payment invoice uuid or id as string |
| `invoiceNo` | ?string | Invoice `outTradeNo` for display and lookup |
| `paymentStatus` | ?string | Cached invoice status for list views |

Trade MUST NOT require an ORM `ManyToOne` relation to `Payment\Entity\Invoice` in the first phase.

### 9.3 Payment Request Flow

```
POST /api/v1/app/orders/{id}/payment
  -> Trade OrderController validates owner and order status
  -> Trade OrderService calls InvoiceServiceInterface::createInvoice()
  -> Trade OrderService calls InvoiceServiceInterface::pay()
  -> Payment gateway returns PaymentResult
  -> response contains invoice and payment instructions
```

### 9.4 Payment Confirm Flow

```
Provider webhook or wallet success
  -> PaymentNotifyController
  -> Gateway::notify()
  -> InvoiceService::handleNotifyResult()
  -> invoice workflow mark_paid
  -> dispatch InvoicePaidEvent
  -> Trade listener validates source/amount/currency
  -> order workflow pay
  -> OrderService persists paidAt/paymentMethod/paymentStatus
```

### 9.5 Refund Flow

```
POST /api/v1/manage/orders/{id}/refund
  -> Trade validates order can refund
  -> Trade calls InvoiceServiceInterface::refund()
  -> Payment gateway performs refund or mock refund
  -> invoice workflow partial_refund/refund
  -> dispatch InvoiceRefundedEvent
  -> Trade listener applies order refund transition when full refund is confirmed
```

### 9.6 Trade Listener Contract

**File**: `src/Trade/EventListener/OrderInvoiceListener.php`

The listener MUST:

| Rule | Requirement |
|------|-------------|
| T-PAY-1 | Ignore invoices whose `sourceType` is not `trade_order` |
| T-PAY-2 | Find order by `sourceId` |
| T-PAY-3 | Validate invoice amount equals order total amount |
| T-PAY-4 | Validate invoice currency equals order currency |
| T-PAY-5 | Apply order workflow transition only if `can()` returns true |
| T-PAY-6 | Treat duplicate paid events as idempotent no-ops |
| T-PAY-7 | Persist order through `OrderService`, not direct EntityManager logic in listener |

---

## 10. API Design

### 10.1 App Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/app/invoices` | List current user's invoices |
| GET | `/api/v1/app/invoices/{id}` | Invoice detail |
| POST | `/api/v1/app/invoices/{id}/pay/{payment}` | Start payment for an invoice |

### 10.2 Manage Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/manage/invoices` | Admin invoice list |
| GET | `/api/v1/manage/invoices/{id}` | Admin invoice detail |
| POST | `/api/v1/manage/invoices` | Admin create invoice, mostly for manual/testing use |
| POST | `/api/v1/manage/invoices/{id}/pay/{payment}` | Admin start payment |
| POST | `/api/v1/manage/invoices/{id}/cancel` | Cancel unpaid invoice |
| POST | `/api/v1/manage/invoices/{id}/refund` | Refund paid invoice |
| GET | `/api/v1/manage/invoices/{id}/transitions` | Available invoice transitions |

### 10.3 Trade Convenience Endpoints

Trade MAY expose convenience routes that call Payment services:

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/app/orders/{id}/payment` | Create/start invoice payment for order |
| POST | `/api/v1/manage/orders/{id}/payment` | Admin create/start order payment |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund order through linked invoice |

### 10.4 Response Envelope

All Payment API endpoints MUST use the standard response envelope:

```json
{
  "data": {},
  "code": 200,
  "message": "SUCCESS"
}
```

Webhook endpoints are the exception: they MAY return provider-required raw text/XML/JSON responses.

---

## 11. Configuration

### 11.1 Package Config

**File**: `config/packages/payment.yaml`

```yaml
payment:
    default_currency: 'CNY'
    gateways:
        mock:
            enabled: true
        wallet:
            enabled: true
```

### 11.2 Future Provider Config Shape

Provider configuration shape is reserved but not implemented in the first phase:

```yaml
payment:
    gateways:
        fuiou:
            enabled: '%env(bool:PAYMENT_FUIOU_ENABLED)%'
            merchant_id: '%env(PAYMENT_FUIOU_MERCHANT_ID)%'
            merchant_key: '%env(PAYMENT_FUIOU_MERCHANT_KEY)%'
            notify_url: '%env(PAYMENT_FUIOU_NOTIFY_URL)%'
        huifu:
            enabled: '%env(bool:PAYMENT_HUIFU_ENABLED)%'
            sys_id: '%env(PAYMENT_HUIFU_SYS_ID)%'
            private_key: '%env(PAYMENT_HUIFU_PRIVATE_KEY)%'
            public_key: '%env(PAYMENT_HUIFU_PUBLIC_KEY)%'
            platform_public_key: '%env(PAYMENT_HUIFU_PLATFORM_PUBLIC_KEY)%'
```

Sensitive provider values MUST come from environment variables and MUST NOT be committed.

---

## 12. Transaction Contract

### 12.1 Service-Level Transactions

Payment mutations MUST be managed in the service layer.

| Operation | Transaction Boundary |
|-----------|----------------------|
| Create invoice | `InvoiceService::createInvoice()` |
| Start wallet payment | `InvoiceService::pay()` and `WalletGateway` transfer service |
| Handle webhook | `InvoiceService::handleNotifyResult()` |
| Refund invoice | `InvoiceService::refund()` |

### 12.2 Cross-Module Consistency

Payment and Trade SHOULD NOT attempt a distributed transaction.

Instead:

| Concern | Mechanism |
|---------|-----------|
| Invoice paid persisted but order update fails | Event listener logs failure and retry command can replay invoice event |
| Duplicate provider callback | Invoice idempotency rules |
| Order already paid | Trade listener no-op if order is already paid |
| Amount mismatch | Trade listener rejects transition and logs critical error |

### 12.3 Webhook Locking

Webhook handling SHOULD use one of these idempotency mechanisms:

| Mechanism | Use |
|-----------|-----|
| Unique `outTradeNo` | Locate invoice |
| Provider `transactionId` | Prevent duplicate provider success processing |
| Optional lock key | `payment_notify_{payment}_{outTradeNo}` |
| Stored raw event id | Future provider-specific duplicate detection |

---

## 13. Error Handling

### 13.1 Exceptions

| Exception | When |
|-----------|------|
| `PaymentGatewayNotFoundException` | Unknown gateway name |
| `PaymentVerificationException` | Signature or provider payload verification fails |
| `InvoiceInvalidTransitionException` | Workflow transition is not allowed |
| `InvoiceAmountMismatchException` | Notify/refund amount does not match invoice rules |

All exceptions MUST be thrown by services or gateway adapters, never by entities.

### 13.2 Controller Mapping

Business API controllers convert exceptions to `warning()` responses.

Webhook controllers return provider-compatible failure responses when verification fails.

---

## 14. Security

### 14.1 App and Manage APIs

| Scope | Security |
|-------|----------|
| `/api/v1/app/invoices*` | Authenticated user, scoped to payer/source ownership |
| `/api/v1/manage/invoices*` | `ROLE_ADMIN` |
| Trade convenience endpoints | Same as existing Trade route security |

### 14.2 Webhook APIs

Webhook endpoints are publicly reachable but MUST verify provider signatures or shared secrets.

Rules:

| Rule | Requirement |
|------|-------------|
| W1 | Never trust request body before verification |
| W2 | Log sanitized payloads only |
| W3 | Do not log secret keys, signatures with private material, access tokens, or full PII |
| W4 | Webhook must be idempotent |
| W5 | Webhook response must follow provider protocol |

---

## 15. Provider Adapter Framework Reference

### 15.1 Fuiou/Huifu Lessons

The legacy provider bundles demonstrate that a provider integration may contain several independent capabilities:

| Capability | Examples | First Phase |
|------------|----------|-------------|
| Payment collection | Fuiou/Huifu normal pay, POS pay | Framework only |
| Payment notify | Signature verification and status mapping | Framework only |
| Refund | Provider refund API | Framework only |
| Account onboarding | Fuiou/Huifu account entities | Excluded |
| POS device management | POS terminal entities | Excluded |
| Withdraw/settlement | Auto withdraw commands | Excluded |

### 15.2 Future Provider Structure

Provider collection adapters SHOULD be implemented as gateway services:

```text
Payment\Service\Gateway\Fuiou\FuiouGateway
Payment\Service\Gateway\Fuiou\FuiouPosGateway
Payment\Service\Gateway\Huifu\HuifuGateway
Payment\Service\Gateway\Huifu\HuifuPosGateway
```

Provider account modules MAY be added later:

```text
Payment\Provider\Fuiou\Entity\FuiouAccount
Payment\Provider\Fuiou\Entity\FuiouPosDevice
Payment\Provider\Huifu\Entity\HuifuAccount
Payment\Provider\Huifu\Entity\HuifuPosDevice
```

Provider account modules MUST NOT be required for generic invoice payment.

---

## 16. Testing Contract

### 16.1 Unit Tests

| Test | Coverage |
|------|----------|
| `InvoiceTest` | Status constants, timestamps, amount/refund getters, `__toString()` |
| `PaymentGatewayRegistryTest` | Resolve gateway, unknown gateway exception, names list |
| `InvoiceServiceTest` | Create invoice, pay, mark paid, cancel, refund validation |
| `MockGatewayTest` | Pay/notify/refund deterministic behavior |
| `WalletGatewayTest` | Wallet transfer success/failure mapping |

### 16.2 Integration Tests

| Test | Coverage |
|------|----------|
| `PaymentApiIntegrationTest` | App/manage invoice list/detail/pay/refund endpoints |
| `PaymentWebhookIntegrationTest` | Webhook success, invalid signature, duplicate notify |
| `TradePaymentIntegrationTest` | Order payment creates invoice and paid event updates order |
| `TradeRefundIntegrationTest` | Order refund through invoice updates both modules |

### 16.3 Regression Cases

| Case | Expected |
|------|----------|
| Duplicate paid notify | No duplicate order transition |
| Notify amount mismatch | Invoice not marked paid; warning/failed handling logged |
| Refund more than paid | Rejected |
| Pay cancelled invoice | Rejected |
| Unknown gateway | 400 warning for API, provider failure response for webhook |

---

## 17. Implementation Plan

### Phase 1: Core Invoice Framework

1. Create `Payment` module skeleton.
2. Add `Invoice` entity and repository.
3. Add invoice workflow configuration.
4. Add DTOs and exceptions.
5. Add `PaymentGatewayInterface` and `PaymentGatewayRegistry`.
6. Add `InvoiceServiceInterface` and `InvoiceService`.
7. Add `MockGateway`.
8. Add App/Manage invoice controllers.
9. Add webhook controller.
10. Add unit and integration tests.

### Phase 2: Wallet Gateway and Trade Integration

1. Add `WalletGateway` using `TransferServiceInterface`.
2. Add lightweight invoice reference fields to `Trade\Entity\Order`.
3. Add Trade payment convenience endpoint.
4. Add `OrderInvoiceListener` for invoice paid/refunded events.
5. Replace direct wallet payment route with Payment-backed path or keep direct path temporarily behind explicit compatibility.
6. Add Trade payment integration tests.

### Phase 3: Provider Adapter Readiness

1. Define provider config schema for external gateways.
2. Add abstract signer/parser client patterns if needed.
3. Add provider adapter tests with fake payloads.
4. Document required certification/manual verification steps.

### Phase 4: Fuiou/Huifu Providers

This phase is intentionally out of first implementation scope.

When started, migrate only provider payment gateway responsibilities first:

1. `FuiouGateway` and `FuiouPosGateway`.
2. `HuifuGateway` and `HuifuPosGateway`.
3. Signature verification helpers.
4. Notify payload parsers.
5. Refund request helpers.
6. Provider-specific raw response formatting.

Do not migrate account onboarding, POS management, or auto withdraw in this phase unless explicitly required.

---

## 18. Open Questions

| Question | Default Decision |
|----------|------------------|
| Should invoice source id use order id or uuid? | Use order uuid for external stability |
| Should invoice relate to User directly? | Optional `payer`, plus source metadata |
| Should Trade hold ORM relation to Invoice? | No, store lightweight invoice reference fields |
| Should provider gateway update Order directly? | No, events only |
| Should Fuiou/Huifu be standalone bundles? | No, future gateway adapters under Payment |
| Should PaymentRequisition be included? | No, separate Finance/Approval module later |

---

## 19. Acceptance Criteria

The Payment module design is implemented when:

| Criteria | Requirement |
|----------|-------------|
| Invoice entity | Exists with cents-based amount and workflow status |
| Gateway registry | Resolves tagged gateways by name |
| Mock gateway | Supports deterministic pay/notify/refund tests |
| Webhook | Updates invoice through service, not controller logic |
| Events | Invoice paid/refunded/cancelled events dispatched |
| Trade integration | Order can be paid through invoice event flow |
| Tests | Unit and integration tests cover idempotency and invalid transitions |
| CI | Coverage remains at or above required threshold |
