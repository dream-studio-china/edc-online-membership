# Store Runbook

This runbook is the operational guide for configuring and operating the Store
module (multi-store operations, store orders, membership). It complements
[`design/bundles/store.md`](../design/bundles/store.md).

## 1. Concepts In One Minute

- **Store** is the multi-store tenant boundary.
- **StoreOrder** tracks fulfillment of a store-scoped Trade order (`operationalStatus`:
  `pending_validation → awaiting_inventory → accepted → fulfilling/fulfilled → verified`,
  plus `rejected` / `cancelled`).
- **Membership** tracks store-level membership.
- Store context is resolved per request and drives promotion and inventory scoping.

## 2. Store Context

A `StoreContextResolver` resolves the current store from the `X-Store-Code`
request header. It is bound to
`App\Trade\Service\StoreContextResolverInterface`. Unknown or inactive codes
throw `404 Store is not available`. Store context drives:

- Promotion availability (`storeCode`).
- Inventory scoping (per-store stock).
- Order store association, currency authoritativeness (mismatched `currency`
  is rejected with `400 Currency mismatch`), and the `_completionMode`
  snapshot (`store_verification` when the Store's
  `settings.fulfillment.requireVerification` is `true`, else `manual`).

## 3. Store Order Flow

A store-scoped Trade order auto-submits on creation and emits
`trade.order.created.v1` for the Store consumer (plain orders without
`X-Store-Code` stay `draft` with no outbox):

```
POST /api/v1/app/orders + X-Store-Code → trade pending → store pending_validation
  → accepted (INVENTORY_ENABLED=0) / awaiting_inventory → accepted (INVENTORY_ENABLED=1)
  → fulfilling/fulfilled → verified (only when verificationRequired=true)
```

There is no "store submit / accept / reject" transition endpoint. Acceptance is
automatic (or via the inventory reservation path); rejection/cancellation is
recorded on the `StoreOrder`. Fulfillment and verification are staff actions
(see §4). Verification emits `store.order.verified.v1`, which completes the
Trade order (`fulfilled → completed`) via the async relay.

## 4. Store Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/stores` | List stores |
| POST | `/api/v1/manage/stores` | Create store (`code`, `name`, `timezone` required) |
| GET / PUT | `/api/v1/manage/stores/{uuid}` | Store detail / update |
| POST | `/api/v1/manage/stores/{uuid}/status/{activate\|suspend\|close}` | Change store status |
| GET | `/api/v1/manage/stores/{uuid}/members` | List store members |
| POST | `/api/v1/manage/stores/{uuid}/members` | Grant membership (`userUuid`, `role`) |
| GET | `/api/v1/manage/store-orders` | List store orders (read-only) |
| GET | `/api/v1/manage/store-orders/{id}` | Store order detail (read-only) |
| GET | `/api/v1/store/{storeUuid}/orders` | Staff: list scoped orders |
| GET | `/api/v1/store/{storeUuid}/orders/{orderUuid}` | Staff: scoped order detail |
| POST | `/api/v1/store/{storeUuid}/orders/{orderUuid}/fulfill` | Staff: fulfill (requires `accepted` / `fulfillment_pending` / `fulfilling`) |
| POST | `/api/v1/store/{storeUuid}/orders/{orderUuid}/verify` | Staff: verify (requires `fulfilled` + `verificationRequired=true`; emits `store.order.verified.v1`) |

## 5. Console Commands

No store admin commands exist beyond the outbox publisher (verified: only
`src/Store/Command/PublishOutboxCommand.php` under `src/Store/Command/`).

| Command | Purpose |
|---------|---------|
| `php bin/console app:store:outbox:publish --no-interaction` | Publish pending Store outbox topics (`store.order.verified.v1`, `inventory.reservation.requested.v1`, `inventory.reservation.release.requested.v1`) to the `async` transport |
| `php bin/console app:trade:outbox:publish --no-interaction` | Publish `trade.order.created.v1` / `trade.order.cancelled.v1` (feeds the Store consumer) |
| `php bin/console app:inventory:outbox:publish --no-interaction` | Publish inventory reservation outcomes (needed when `INVENTORY_ENABLED=1`) |
| `php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction` | One-shot async relay (unpacks outbox events into `store_order` rows / status moves) |
| `php bin/console app:identity:user:create <email> <username> <password> [--admin] [--role=ROLE_X] [--phone=...] [--phone-verified]` | Create a user (used by smoke tests to bootstrap `admin@example.com`) |
| `php bin/console app:authorization:seed` | Seed Authorization permissions/roles/field-grants (idempotent; covers `store:order:*`, `store:product:*`, `store:specification:*`) |

The Docker `scheduler` runs the full publish loop every
`${OUTBOX_PUBLISH_INTERVAL:-5}` seconds (see §7).

## 6. Smoke Test

`scripts/tests/store-smoke.sh` — store order orchestration. Requires a running
server (`BASE` default `http://127.0.0.1:8000`), `curl`, `python3`, `symfony` CLI.

What it covers:

1. Bootstraps `admin@example.com` via `app:identity:user:create ... --admin` if login fails.
2. Creates a store (`POST /api/v1/manage/stores`) and grants the admin `manager`
   membership (`POST /api/v1/manage/stores/{uuid}/members`).
3. Creates a product + specification.
4. Optional inventory path when `INVENTORY_ENABLED=1` (default `0`): creates a
   material, sets `allowNegativeStock=true`, adjusts stock, creates a recipe.
5. Creates a store-scoped order (`POST /api/v1/app/orders` with `X-Store-Code` header).
6. Runs the production relay: `app:trade:outbox:publish` → `messenger:consume async`.
7. Expects staff `GET /api/v1/store/{uuid}/orders` to show `accepted`
   (`awaiting_inventory` first when `INVENTORY_ENABLED=1`, then `accepted` after
   the store/inventory publish + consume steps).
8. Runs `app:store:outbox:publish` → `messenger:consume async` and expects the
   Trade order (`GET /api/v1/app/orders/{id}`) to reflect Store acceptance.

## 7. Docker Ops

Services in `compose.yaml`: `app` (PHP-FPM), `worker`, `scheduler`, `nginx`,
`database` (MySQL 8), `redis`, `mailer` (Mailpit).

- **worker** consumes the `async` transport:
  `php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-interaction`.
  All Store/Trade/Inventory messages route to `async` (`config/packages/messenger.yaml`);
  failures go to the `failed` transport (`doctrine://default?queue_name=failed`).
- **scheduler** loops every `${OUTBOX_PUBLISH_INTERVAL:-5}` (default `5` seconds):
  `app:trade:outbox:publish`, `app:store:outbox:publish`,
  `app:inventory:outbox:publish`, `app:inventory:reservations:release-expired`,
  `app:settlement:allocations:requeue-due`, `app:settlement:outbox:publish`.
- **JWT keys** (`docker/app/entrypoint.sh`): if both keys exist they are reused;
  in non-prod missing keys are auto-generated (2048-bit RSA, private key mode
  `600`, persisted under mounted `var/`); in `prod` missing keys abort startup —
  generate on the host first.
- **Env vars**: `compose.prod.yaml` requires `APP_SECRET`,
  `REFRESH_TOKEN_SECRET`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`. Store-relevant
  runtime knobs: `INVENTORY_ENABLED` (default `0`), `OUTBOX_PUBLISH_INTERVAL`
  (default `5`), `MESSENGER_TRANSPORT_DSN` (default
  `doctrine://default?auto_setup=0`).
- **Healthchecks**: public `GET /health/live` (always 200 while PHP serves) and
  `GET /health/ready` (DB required, Redis optional via `OTP_REDIS_DSN`); the
  `nginx` healthcheck polls `/health/ready` through the full stack.
- **Metrics**: public `GET /metrics` exposes `app_outbox_backlog{topic="store"}`
  (unpublished `store_outbox_message` rows) and `app_messenger_failed`
  (`messenger_messages` where `queue_name='failed'`).

## 8. Seed / Migrate Sequence (fresh deploy affecting store/trade)

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
docker compose exec app php bin/console app:authorization:seed
```

Production adds `-f compose.yaml -f compose.prod.yaml --env-file .env.prod.local`
to each `docker compose` invocation. Check pending migrations with
`doctrine:migrations:status`.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Store not resolved | Missing `X-Store-Code` header | Send `X-Store-Code: <code>`; resolver returns `null` without it |
| `404 Store is not available` | Unknown or inactive store code | Check store `code` and status (`activate` via `POST /api/v1/manage/stores/{uuid}/status/activate`) |
| `400 Currency mismatch` | Request `currency` differs from Store currency | Omit `currency` or match the Store's currency |
| Store order never appears | Outbox relay not running | Run `app:trade:outbox:publish` then `messenger:consume async`; check `app_outbox_backlog{topic="trade"}` at `/metrics` |
| Stuck in `awaiting_inventory` | Inventory relay not running or stock short | Run `app:store:outbox:publish` → consume → `app:inventory:outbox:publish` → consume; check stock and `allowNegativeStock` |
| `Store verification is disabled` on verify | Order snapshot `verificationRequired=false` | Verification only applies when the Store had `settings.fulfillment.requireVerification=true` at order creation; immutable per order |
| `Store order cannot be verified in its current status` | Not `fulfilled` yet, or already `verified` | Fulfill first (`POST .../fulfill`); check `operationalStatus` / `verifiedAt` |
| `Trade fulfilled → completed` never happens | `store.order.verified.v1` not relayed, or order is `manual` mode | Run `app:store:outbox:publish` + consume; only `_completionMode=store_verification` orders complete via verification (manual `do/complete` is blocked with `Store verification is required`) |
| Promotion not scoped | `storeCode` mismatch | Verify promotion `storeCode` |
| Failed async messages piling up | Handler exception / retry exhaustion | Check `app_messenger_failed` at `/metrics`; inspect `messenger_messages` rows with `queue_name='failed'` |

## 10. Checklist Before Going Live

- [ ] Stores created and `activate`d.
- [ ] Store context (`X-Store-Code`) verified; currency matches.
- [ ] `settings.fulfillment.requireVerification` set per business need (immutable per order once snapshotted).
- [ ] Staff memberships granted; `store:order:fulfill` / `store:order:verify` permissions seeded via `app:authorization:seed`.
- [ ] Promotions and inventory scoped to stores.
- [ ] `worker` + `scheduler` running; `/metrics` backlogs near zero; `/health/ready` returns 200.
