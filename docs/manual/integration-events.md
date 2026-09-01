# Integration Events

## 1. Outbox/Inbox Pattern

The platform uses the **Transactional Outbox** pattern for all cross-service
integration events. This guarantees at-least-once delivery without distributed
transactions (2PC).

### Why Outbox?

When a service mutates its state and needs to notify other services, it must
ensure both the state change and the notification happen atomically. Without
an Outbox, failure between the DB write and the message publish creates inconsistency.

### How It Works

```
┌─────────────────────────────────────────────────────┐
│  Service A (Producer)                               │
│                                                     │
│  ┌───────────────────────┐  ┌────────────────────┐  │
│  │  Business Transaction │  │ Scheduler (cron)   │  │
│  │  1. Update entity     │  │ Every 5 seconds    │  │
│  │  2. INSERT Outbox row │  │ SELECT unclaimed   │  │
│  │  3. COMMIT            │  │ UPDATE claimed=true│  │
│  └───────────────────────┘  │ Dispatch to queue  │  │
│                             └─────────┬──────────┘  │
└───────────────────────────────────────┼─────────────┘
                                        │
                          Messenger Transport (Doctrine)
                                        │
┌───────────────────────────────────────┼──────────────┐
│  Service B (Consumer)                 │              │
│                                       ▼              │
│  ┌────────────────────────────────────────────────┐  │
│  │  Message Handler                               │  │
│  │  1. Extract envelope from carrier              │  │
│  │  2. Check Inbox: SELECT WHERE eventId = ?      │  │
│  │  3. If exists → ACK (idempotent)               │  │
│  │  4. If not → process + INSERT Inbox row        │  │
│  │  5. Process in transaction                     │  │
│  └────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

### Key Properties

| Property | Mechanism |
|----------|-----------|
| **Atomicity** | Outbox write in same DB transaction as business state change |
| **At-least-once** | Scheduler relays to Messenger; worker retries on failure |
| **Idempotency** | Consumer checks Inbox by `eventId` — duplicate = no-op |
| **Ordering** | Within a single aggregate: Outbox `created_at` ordering |
| **Claim** | UPDATE WHERE claimed=false → prevents concurrent delivery |

## 2. Envelope Structure

Every integration event uses a canonical envelope defined in
`packages/integration-contracts/`:

```json
{
  "eventId": "01936c8a-1234-7890-abcd-ef0123456789",
  "type": "trade.order.created",
  "version": 1,
  "aggregateType": "Trade\\Trade\\Entity\\Order",
  "aggregateId": "01936c8a-1234-7890-abcd-ef0123456789",
  "occurredAt": "2026-07-31T12:00:00+00:00",
  "correlationId": "01936c8a-0000-7890-abcd-ef0123456789",
  "causationId": null,
  "payload": { ... }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `eventId` | UUIDv4 | Unique identifier for this event. Primary idempotency key. |
| `type` | string | Unversioned event type, e.g., `trade.order.created` |
| `version` | int | Schema version (currently always `1`) |
| `aggregateType` | string | FQCN of the source aggregate |
| `aggregateId` | UUID | ID of the source aggregate |
| `occurredAt` | ISO 8601 | When the event was recorded |
| `correlationId` | UUID | Root correlation ID — links all events in a causal chain |
| `causationId` | UUID\|null | Immediate parent `eventId` that caused this event |
| `payload` | object | Event-specific data (schema per `type`) |

### Broker Topic

The logical broker topic is: `{type}.v{version}`
- `trade.order.created.v1`
- `store.order.accepted.v1`
- `inventory.reservation.confirmed.v1`
- `payment.invoice.paid.v1`
- `store.directory.upserted.v1`

## 3. Existing Integration Events

`packages/integration-contracts` provides transport-neutral v1 carrier classes
for **10** integration messages:

| # | Type | Kind | Producer | Consumer |
|---|------|------|----------|----------|
| 1 | `trade.order.created.v1` | Event | Trade | Store |
| 2 | `trade.order.cancelled.v1` | Event | Trade | Store |
| 3 | `store.order.accepted.v1` | Event | Store | Trade |
| 4 | `store.order.rejected.v1` | Event | Store | Trade |
| 5 | `inventory.reservation.requested.v1` | **Command** | Store | Inventory |
| 6 | `inventory.reservation.confirmed.v1` | Event | Inventory | Store |
| 7 | `inventory.reservation.rejected.v1` | Event | Inventory | Store |
| 8 | `inventory.reservation.release.requested.v1` | **Command** | Store | Inventory |
| 9 | `payment.invoice.paid.v1` | Event | Payment | Trade |
| 10 | `store.directory.upserted.v1` | Event | Store | Trade |

Type 5 and 8 are **Commands** (intent/request), not Events (past-tense facts).

## 4. How to Publish

### Step 1: Write to Outbox in the Same Transaction

```php
final class OrderService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TradeOutboxMessageRepository $outboxRepo,
    ) {}

    public function createOrder(CreateOrderRequest $request): Order
    {
        return $this->em->wrapInTransaction(function () use ($request): Order {
            $order = new Order(/* ... */);
            $this->em->persist($order);

            // Build payload
            $payload = new TradeOrderCreatedPayload(
                orderId: $order->getUuid(),
                items: array_map(fn(OrderItem $item) => [
                    'specificationId' => $item->getSpecificationUuid(),
                    'quantity' => $item->getQuantity(),
                ], $order->getItems()->toArray()),
            );

            // Envelope is created by the carrier class
            $carrier = new TradeOrderCreatedV1($payload, $order);
            $outbox = TradeOutboxMessage::fromCarrier($carrier);

            // Persist in the same transaction
            $this->em->persist($outbox);
            // COMMIT happens here — both Order and Outbox row atomically

            return $order;
        });
    }
}
```

### Step 2: Scheduler Relays Outbox Rows

The scheduler runs `PublishOutboxCommand` every `OUTBOX_PUBLISH_INTERVAL` seconds
(default: 5):

```php
final class PublishOutboxCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Atomically claim unclaimed rows
        $rows = $this->outboxRepo->claimUnpublished(limit: 100);

        foreach ($rows as $row) {
            // Dispatch to Messenger (Doctrine transport)
            $this->bus->dispatch($row->toEnvelope());
            // Row marked as claimed; not deleted
        }

        return Command::SUCCESS;
    }
}
```

### Claim Pattern

The `claimUnpublished()` method uses an atomic `UPDATE` with `WHERE claimed = false`
to prevent duplicate publication by concurrent scheduler instances:

```sql
UPDATE trade_outbox_message
SET claimed = true, claimed_at = NOW()
WHERE id IN (
    SELECT id FROM trade_outbox_message
    WHERE claimed = false
    ORDER BY created_at ASC
    LIMIT 100
)
```

## 5. How to Consume

### Idempotent Consumption

Every consumer MUST be idempotent:

```php
final class TradeOrderCreatedHandler
{
    public function __construct(
        private InboxMessageRepository $inboxRepo,
        private EntityManagerInterface $em,
    ) {}

    #[AsMessageHandler]
    public function __invoke(TradeOrderCreatedV1 $carrier): void
    {
        $envelope = $carrier->envelope;
        $eventId = $envelope->eventId;

        // Idempotency check
        if ($this->inboxRepo->exists($eventId)) {
            return; // Already processed — ACK and move on
        }

        $this->em->wrapInTransaction(function () use ($carrier, $eventId): void {
            // 1. Process the event (business logic)
            $payload = $carrier->envelope->payload;
            $storeOrder = $this->storeOrderService->createFromTradeOrder($payload);

            // 2. Write Inbox record (same transaction)
            $inbox = InboxMessage::consume(
                eventId: $eventId,
                type: $carrier->envelope->type,
                aggregateId: $carrier->envelope->aggregateId,
            );
            $this->em->persist($inbox);

            // COMMIT — StoreOrder + Inbox row atomically
        });
    }
}
```

### Legacy Compatibility

All nine consumers also accept their matching **legacy native-PHP Messenger
wrappers** for backward compatibility with historical queue records. Each handler
method adapts the carrier envelope to the legacy wrapper and reuses the same
business logic, transaction boundaries, and Inbox behavior.

`config/packages/messenger.yaml` explicitly retains Symfony's native PHP
serializer because existing `async` and `failed` rows serialize old
`App\*\Message` wrapper FQCNs.

## 6. Correlation & Causation Tracing

### Trace Propagation

When a consumer produces a derived event (e.g., Store receives Trade order →
publishes inventory reservation request), it propagates trace metadata:

```php
// Store receives trade.order.created.v1 with correlationId=X, eventId=A
// Store publishes inventory.reservation.requested.v1:
$carrier = new InventoryReservationRequestedV1(
    payload: $payload,
    aggregate: $storeOrder,
    correlationId: $inputEnvelope->correlationId,  // Inherited: X
    causationId: $inputEnvelope->eventId,           // Parent: A
);
```

### Chain Example

```
Trade Order Created (correlationId=C1, eventId=E1, causationId=null)
  → Store receives (correlationId=C1, eventId=E1)
  → Store publishes InventoryReservationRequested (correlationId=C1, causationId=E1)
  → Inventory publishes InventoryReservationConfirmed (correlationId=C1, causationId=E2)
  → Store publishes StoreOrderAccepted (correlationId=C1, causationId=E3)
  → Trade receives (correlationId=C1, eventId=E4)
```

`correlationId=C1` ties all events in the chain. `causationId` shows the
immediate parent.

### Legacy Fallback

Legacy Outbox rows without `correlationId` use their own `eventId` as a
compatibility root. Backfill commands exist for unpublished legacy rows.

## 7. Adding a New Integration Event

### Step 1: Define the Carrier

Create in `packages/integration-contracts/src/`:

```php
// packages/integration-contracts/src/TradeOrderUpdatedV1.php
final class TradeOrderUpdatedV1
{
    public function __construct(
        public readonly TradeOrderUpdatedPayload $payload,
        // ... standard envelope args
    ) {}
}

// packages/integration-contracts/src/TradeOrderUpdatedPayload.php
final class TradeOrderUpdatedPayload
{
    public function __construct(
        public readonly string $orderId,
        public readonly array $changedFields,
    ) {}
}
```

### Step 2: Register in Manifest

Update the manifest in `packages/integration-contracts/manifest.json`.

### Step 3: Add Outbox in Producer

In the producing service, write the Outbox row in the same transaction as the
business state change.

### Step 4: Add Inbox Consumer

In the consuming service, add a `#[AsMessageHandler]` method that:
1. Checks Inbox by `eventId`
2. Processes the payload
3. Writes Inbox row in the same transaction

### Step 5: Add Tests

- Carrier round-trip tests
- Publisher tests (Outbox row creation)
- Consumer tests (idempotency, business logic)
- Integration test (end-to-end with SQLite)

### Step 6: Update CI

If the new event involves a new producer/consumer pair, update the per-app
test suites.

## 8. Backfill Commands

Several services have backfill commands for populating correlation metadata on
legacy Outbox rows:

| Command | Service | Purpose |
|---------|---------|---------|
| `app:trade:outbox:backfill-correlation` | Trade | Backfill `correlation_id` on unpublished Trade Outbox rows |
| `app:store:outbox:backfill-correlation` | Store | Backfill `correlation_id` on unpublished Store Outbox rows |
| `app:store:outbox:backfill-directory` | Store | Backfill directory events into Outbox for Trade projection |
| `app:inventory:outbox:backfill-correlation` | Inventory | Backfill `correlation_id` on Inventory Outbox rows |
| `app:payment:outbox:backfill-correlation` | Payment | Backfill `correlation_id` on Payment Outbox rows |

All backfill commands follow the **dry-run / `--apply`** pattern:

```bash
# Dry run (safe, shows what would change)
php bin/console app:trade:outbox:backfill-correlation --batch-size=500

# Apply (actually updates)
php bin/console app:trade:outbox:backfill-correlation --batch-size=500 --apply
```

This allows operators to inspect changes before applying them and run in
bounded, resumable batches.

## 9. Legacy Messenger Compat

`packages/legacy-messenger-compat/` contains historical native-PHP Messenger
wrapper FQCNs. These exist for backward compatibility with existing queue
records and should **not** be used for new messages.

Old wrappers remain as compatibility input for historical queue records and
as a topic-level Publisher rollback target. New publishers emit only neutral
carriers. Do **not** dual-publish.

### When Legacy Wrappers Can Be Removed

After all historical `async` and `failed` Messenger rows with old FQCNs are
drained or migrated. This requires an operational purge of the doctrine
Messenger transport.
