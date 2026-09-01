# Payment Extraction Readiness

> **Payment source has been fully extracted.** This document records the
> completed transition-host work and the remaining runtime cutover steps.

## Completed

- Payment source now lives exclusively in `apps/payment/src` under
  `App\Payment\*` (30 files).
- `apps/payment` has its own Kernel, Composer lock, composer.json (`crud-platform/payment-app`), configuration, PHPUnit smoke test, FrankenPHP image, and MySQL 8.4 service.
- The monolith temporarily loads Payment through the `crud-platform/payment-app` Composer path package; root routes, Doctrine mapping, services, PHPStan, Deptrac, and Rector point to that package.
- The Payment baseline migration owns `payment_invoice`, `payment_outbox_message`, and `payment_payer_directory` — three Payment-owned tables with no foreign keys to non-Payment tables.
- Payment invoices use a Payment-owned `payer_uuid` scalar instead of an ORM association to Identity `users`. The `payment_payer_directory` maps legacy numeric Identity IDs to UUIDs during the transition.
- Payment's `InvoiceService` writes durable outbox rows; the transition host's `app:payment:outbox:publish` publishes paid/failed/cancelled/refunded v1 contracts through the root async transport.
- Trade consumes those contracts via an idempotent `trade_consumed_event` inbox; the synchronous `OrderInvoiceListener` remains temporarily for observation.
- Wallet uses a Core UUID-to-local-ID contract, and WeChat resolves its own user relation by UUID. Payment no longer imports Identity entities or repositories.
- Wallet deduction persistence stores Payment references as `invoice_id` and `invoice_no` scalars. Its entity and repository no longer import the Payment Invoice entity.
- Wallet payment and deduction services now accept Wallet-owned scalar references only. Payment owns the gateway and adjustment-provider composition adapters; the root `App\Bridge\PaymentWallet\WalletBalanceAdjustmentPort` translates the Payment scalar port to Wallet persistence.
- Wallet now dual-writes an `owner_uuid` beside its legacy `user_id` association. App Wallet filtering and owner-level reconciliation use the UUID; the legacy foreign key remains until the Wallet baseline and Trade integration are ready.
- Trade's legacy wallet pay/refund path uses the neutral UUID-based `WalletTransferPortInterface`; it no longer imports Wallet repositories or transfer services.
- Root Dockerfile copies `apps/payment` before `composer install`, and the root scheduler publishes Payment outbox rows independently.
- The Payment app resolves its own `PayerReferenceResolverInterface` through `PayerDirectoryReferenceResolver` in standalone mode; the monolith binds it to the Identity adapter.
- Payment app includes its own `invoice` workflow state machine.

## Transition Safety

The existing synchronous `OrderInvoiceListener` remains temporarily enabled.
It preserves current API behavior while the durable path is observed. The Trade
Inbox makes the asynchronous copy safe to replay: it records the event ID and
payload hash, treats an identical retry as a no-op, and rejects an event ID
reused with a different payload.

Do not remove the synchronous listener until the following have been observed:

1. Payment Outbox publishing and Trade consumer processing are healthy for a
   bounded production period.
2. Paid, failed, cancelled, partial-refund, and full-refund paths have all been
   exercised through the durable path.
3. Failed transport rows and any in-flight events have been replayed.
4. The resulting eventual-consistency behavior is accepted by the payment and
   order API clients.

## Remaining Extraction Work

1. Introduce the shared broker and schema-versioned serializer required for an
   independent Payment worker and database. Do not route Payment traffic to
   `payment-app` before this is complete: its local Doctrine transport cannot
   deliver lifecycle events to Trade.
2. Disable and remove the synchronous `OrderInvoiceListener` after the durable
   path has met the observation criteria.
3. Add Gateway-issued authentication before exposing protected Payment routes;
   the current standalone security provider is intentionally a placeholder.
4. Move Payment HTTP traffic, worker, and scheduler behind Gateway shadow
   routing, then remove the monolith host assembly.
5. After `owner_uuid` has been observed in production, remove Wallet's legacy
   Identity `User` association. Then provide Wallet with its own baseline, app
   runtime, and shared messaging/auth dependencies before extracting it from the
   transition host.
