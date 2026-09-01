# Store Extraction Readiness

> **Store source has been fully extracted.** This document tracks the completed
> extraction and remaining cutover steps.

## Current Scope

Store source now lives exclusively in `apps/store/src` (34 files). The monolith
loads Store through a Composer `crud-platform/store-app` path package as a
transitional host. Store-owned runtime responsibilities are:

- Store, membership, StoreOrder, Inbox, Outbox, and cancellation-tombstone data.
- App, manage, and staff HTTP endpoints under the existing `/api/v1` gateway prefix.
- Trade and Inventory integration consumers (dual-compatible with legacy wrappers).
- Store Outbox publisher, correlation backfill, and directory backfill commands.
- Store directory `onFlush` listener feeding the `store.directory.upserted.v1` event.

Entities have been de-prefixed within the `App\Store` namespace:
`StoreMembership` → `Membership`, `StoreConsumedEvent` → `InboxMessage`,
`StoreOutboxMessage` → `OutboxMessage`, `StoreTradeOrderCancellation` →
`TradeOrderCancellation`. Physical `store_*` table names are unchanged.

The owned data set is:

```text
store
store_membership
store_order
store_consumed_event
store_outbox_message
store_trade_order_cancellation
```

`store_trade_order_cancellation` is included in the Store application schema
baseline at `apps/store/migrations/Version20260730000000.php` for independent
database cutover.

## Dependency Inventory

| Dependency | Current use | Extraction disposition |
|---|---|---|
| `App\Core` | REST controller, view traits, BaseService, UUID | Provided by `packages/platform-kernel`; Store depends on this library. |
| `CrudPlatform\IntegrationContracts` | All integration messages | Keep; this is the approved cross-service dependency. |
| `App\Trade\Message` / `App\Inventory\Message` | Legacy native-Messenger compatibility adapters | Provided by `packages/legacy-messenger-compat` until old `async`/`failed` records are drained or migrated. |
| Store directory lookup | `store.directory.upserted.v1` feeds Trade's local `trade_store_directory` projection | Resolved. Trade no longer injects a Store repository or service. |
| `App\Identity\Main\Entity\User` | App/staff controllers used it only to read UUID | Resolved. Store depends on `App\Core\Security\UserUuidPrincipalInterface`, implemented by Identity User. |
| `config/routes.yaml` | Imports `src/Store/Controller` | Now points to `vendor/crud-platform/store-app/src/Controller` via path package. |
| Root scheduler/worker | Runs Store Outbox publishing | Runs alongside Trade/Inventory in monolith; Store app has own worker/scheduler capacity post-cutover. |
| Root Doctrine mapping | `src/Store/Entity` | Now points to `vendor/crud-platform/store-app/src/Entity` via path package. |

No Store Entity or Repository imports another module's Entity or Repository. The
Deptrac baseline contains no Store-to-Identity persistence exemption.

## Remaining Cutover Steps

1. Perform a zero-data production rehearsal: deploy Store app, run baseline
   migration, smoke-test HTTP routes and Outbox/Inbox behavior.
2. Drain or migrate legacy native-PHP queue records before Store owns its own
   worker queue. Do not remove legacy wrapper adapters earlier.
3. Configure Store-owned worker and scheduler after remaining modules are extracted
   and Gateway shadow routing is ready.
4. Gateway cutover: point Store routes to the `store-app` container.
5. Observe, then clean up monolith Store host assembly (Composer path package,
   Doctrine mapping, routes, service scan).

## Extraction Status

```text
✅ packages/platform-kernel — Core as shared Composer package
✅ apps/store skeleton with independent Kernel/config/composer/tests
✅ Store source, routes, commands moved to apps/store/src
✅ Entities de-prefixed (Membership, InboxMessage, OutboxMessage, TradeOrderCancellation)
✅ Store schema baseline (6 tables) verifiable in independent MySQL
✅ Trade local projection for X-Store-Code resolution
✅ Store directory Outbox listener + backfill command
✅ Dual-compatible consumers with legacy-messenger-compat
✅ FrankenPHP Docker stack with independent store-app + store-database
🔲 Store worker/scheduler cutover
🔲 Gateway shadow routing
🔲 Monolith Store host cleanup (Composer path package, Doctrine mapping, routes)
🔲 Legacy queue drain → remove legacy-messenger-compat
```
