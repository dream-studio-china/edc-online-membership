# Microservice Transition Contract

> Target architecture and migration rules for the transition from the current
> modular monolith to independently deployable services. This document does not
> claim that the current modules are already services.

---

## 1. Decision

The platform will evolve as a **monorepo containing multiple deployable Symfony
applications**. A service owns its runtime, database schema, migrations, worker
queues, scheduled jobs, and deployment artifact. Splitting into separate Git
repositories is explicitly deferred until service boundaries and contracts are
stable.

The first migration stage establishes the target directory structure and
dependency governance. It does not move every current `src/*` module or split
databases prematurely.

## 2. Target Repository Structure

```text
apps/
  commerce/               # Transitional home for Catalog, Ordering, Pricing
  store/
  inventory/
  payment/
  wallet/
  identity/
  content/
packages/
  platform-kernel/        # Framework-only HTTP/CRUD/observability utilities
  integration-contracts/  # Versioned transport-neutral event schemas
  test-support/
contracts/
  integration/            # Manifest, schemas, and compatibility fixtures
  openapi/                # Published service API contracts
infrastructure/
  docker/
  local/
  gateway/
  rabbitmq/
docs/
tools/
```

Each `apps/*` is independently buildable and contains its own
`composer.json`, `src/Kernel.php`, `config/`, `migrations/`, `public/`, `bin/`,
`tests/`, and Docker build definition. A root Composer workspace may coordinate
local development, but it must not conceal an undeclared runtime dependency.

## 3. Current And Target Boundaries

| Target context | Current source | Initial position |
|---|---|---|
| Platform Kernel | Core | Shared library, never a business service |
| Identity & Access | Identity plus Wechat login adapter | Later extraction |
| Commerce | Trade plus Promotion | Transitional service; Catalog and Ordering separate later |
| Store Operations | Store → `apps/store` | Extracted; monolith hosts during transition |
| Inventory | Inventory → `apps/inventory` | Extracted; monolith hosts during transition; production enablement remains blocked by its safety checklist |
| Payments | Payment plus Wechat Pay adapter | After durable payment lifecycle events exist |
| Wallet/Ledger | Wallet | After Payment contracts are scalar and durable |
| Content/Media | Common plus Storage adapter | Later; split settings ownership from CMS first |

`Wechat` is a provider integration namespace, not a future bounded context. Its
login functions belong behind Identity adapters and its payment functions belong
behind Payment adapters. `Storage` is infrastructure, not a business service by
default.

## 4. Service Boundary Rules

The following rules apply before a module can become independently deployable:

1. A service owns its database and has no cross-service Doctrine association,
   foreign key, repository access, or transaction.
2. Durable cross-service references use UUIDs or documented immutable business
   keys, never another service's local integer primary key.
3. A service boundary accepts scalar commands/queries and emits scalar snapshots.
   It never exposes Doctrine entities, repositories, EntityManagers, Symfony
   requests/responses, or mutable in-process contexts.
4. A state-changing integration event is written to an Outbox in the producer's
   local transaction. Consumers use a durable Inbox (or equivalent consumed-event
   record) keyed by `eventId`.
5. Each consumer owns its queue, retry policy, dead-letter handling, worker, and
   scheduled jobs. A shared Doctrine Messenger queue is not a service boundary.
6. Shared packages may provide framework utilities, schemas, and test support.
   They MUST NOT contain business entities, repositories, or service-specific
   application logic.
7. Public routes remain stable through an API gateway or routing layer while
   ownership moves between services.

## 5. Integration Message Contract

All integration Events and Commands use one versioned envelope:

```json
{
  "eventId": "uuid",
  "type": "store.order.accepted",
  "version": 1,
  "aggregateType": "store_order",
  "aggregateId": "uuid",
  "occurredAt": "2026-07-29T12:00:00+00:00",
  "correlationId": "uuid",
  "causationId": null,
  "payload": {}
}
```

The `type` is unversioned. The broker topic is derived as
`type + ".v" + version`; for example, `store.order.accepted.v1`. All nine fields
are required in canonical v1 envelopes. `causationId` may be null.

The schema and manifest are owned by `contracts/integration`, while PHP carrier
classes live in `packages/integration-contracts`, not in a producer or consumer
Symfony namespace. Changes require a new message version and compatibility
coverage.

Events are past-tense facts with manifest `kind: "event"`. Requests such as
`inventory.reservation.requested.v1` and
`inventory.reservation.release.requested.v1` are Commands with
`kind: "command"`, even though they use the same envelope. Legacy Messenger
wrapper classes live in `packages/legacy-messenger-compat` and remain compatibility
input until old native-PHP serialized queue rows and failed messages have been
drained or migrated.

### 5.1 Carrier Migration Order

Consumers must be deployed before producers change carrier type. During the
compatibility phase, each existing handler accepts both its legacy
`App\*\Message` wrapper and the matching neutral carrier, then delegates to the
same business logic. Producers continue to publish only one carrier per topic;
dual publishing is forbidden because not every consumer action is Inbox-idempotent.

After consumers are deployed, publishers may switch one topic at a time from the
legacy wrapper to the neutral carrier. Rollback changes only that publisher back to
the legacy wrapper. Consumers and old wrapper classes remain in the compatibility
package until the `async` and `failed` queues no longer contain old native-PHP
serialized messages.

All nine Trade, Store, and Inventory topics now publish their matching neutral
carrier. The native PHP serializer remains temporarily, so carrier objects are
still stored in the existing Doctrine queue; the change removes application-module
FQCNs from newly produced messages without changing the transport. A topic-level
rollback changes only its Publisher back to the matching legacy wrapper. Consumers
and wrappers remain until the historical `async` and `failed` queue records have
been drained or migrated.

Store directory ownership is synchronized separately through
`store.directory.upserted.v1`. Store changes write this event in the same
transaction, and Trade maintains a local read-only projection for `X-Store-Code`
resolution. Before switching Trade to this projection in production, run
`app:store:outbox:backfill-directory` as a dry run and then with `--apply` to
initialize events for existing Stores.

### 5.2 Outbox Metadata Expansion

Each producer Outbox stores nullable `correlation_id` and `causation_id` columns
before publishing canonical envelopes. On the supported MySQL 8 runtime the
migration explicitly requests `ALGORITHM=INSTANT`; it performs no
table-wide update and adds no non-null constraint. New root
messages default `correlationId` to their generated `eventId` and use a null
`causationId`. Future handlers that emit a follow-up message must explicitly carry
the inbound `correlationId` and use the inbound `eventId` as `causationId`.

The current Trade -> Store -> Inventory chain applies this propagation for every
derived Outbox message. Legacy envelopes without a correlation ID remain accepted;
their `eventId` becomes the compatibility correlation root. This fallback is
temporary compatibility behavior, not a replacement for canonical envelopes.

Historical rows remain valid with null metadata during this expand phase. A later,
separate, resumable backfill command will update unpublished rows in bounded batches
and report progress. It must be deployed and observed before any non-null constraint
or publisher cutover is considered.

The backfill is intentionally manual and dry-run by default. Run each service
command with a conservative production batch size, observe the reported count and
database load, then repeat until dry-run reports zero:

```bash
php bin/console app:trade:outbox:backfill-correlation --limit=500
php bin/console app:trade:outbox:backfill-correlation --limit=500 --apply
php bin/console app:store:outbox:backfill-correlation --limit=500 --apply
php bin/console app:inventory:outbox:backfill-correlation --limit=500 --apply
```

Each conditional update rechecks that the row is both unpublished and missing a
correlation ID. The command neither updates published rows nor changes
`causation_id`, so it can safely resume after interruption and coexist with the
Outbox Publisher. It must not be added to the scheduler.

## 6. Extraction Order And Gates

The Store-specific dependency inventory and source-move gates are maintained in
[`docs/design/store-extraction-readiness.md`](store-extraction-readiness.md).

The preferred order is:

```text
Store -> Inventory -> Payment -> Wallet
```

Store and Inventory already use UUID/snapshot integration flows and Outbox/Inbox
patterns. They are candidates, not automatically production-ready services.
Inventory must retain its documented feature flag and safety restrictions until
its fulfillment, expiry, ordering, and concurrency guarantees are complete.

Payment, Wallet, Identity, Commerce, Promotion, Content, and provider adapters
must first be decoupled inside the monolith. Examples of required work:

- Replace shared `Identity\Entity\User` associations with external user UUIDs and
  local projections/snapshots.
- Replace Payment's synchronous Doctrine-entity events with Payment Outbox to
  Commerce Inbox events.
- Retire Trade's direct Wallet transfer path in favor of the Payment boundary.
- Replace Payment gateway and adjustment plugin APIs that expose `Invoice` or
  Symfony HTTP types with service-owned commands and result contracts.
- Replace Promotion's shared mutable pricing context with a scalar quote request
  and deterministic result before considering remote execution.

## 7. First-Stage Deliverables

Directory restructuring is considered complete only when all of the following
exist, even before business code is moved:

- The target `apps/`, `packages/`, `contracts/`, `infrastructure/`, and `tools/` ownership
  model is documented and reflected in repository tooling.
- Architecture checks reject forbidden cross-service imports and entity leaks.
- Existing Trade, Store, and Inventory events share the envelope in section 5.
- A service template defines independent Kernel, configuration, migration, queue,
  worker, scheduler, Docker, test, static-analysis, and CI ownership.
- The legacy modular-monolith test suite remains as a characterization suite
  until the relevant service extraction is complete.

## 8. Non-Goals

- Moving directories without enforcing a deployable boundary.
- Creating empty service applications for every current module.
- Extracting `Core` as a network service.
- Splitting historical migrations by filename; mixed legacy migrations remain for
  rebuilding the monolith, while extracted services start from validated schema
  baselines and new service-local migration histories.
