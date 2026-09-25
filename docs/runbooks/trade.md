# Trade Runbook

This runbook is the operational guide for configuring and operating the Trade
module (products, specifications, orders, pricing). It complements
[`design/bundles/trade.md`](../design/bundles/trade.md).

## 1. Concepts In One Minute

- **Product** → **Specification** (SKU) → **OrderItem** → **Order**.
- Orders follow a state machine: `draft → pending → confirmed → paid → fulfilled → completed`
  (`cancelled` from `draft`/`pending`/`confirmed`; `refunded` from `paid` only).
- Pricing runs through a **calculator pipeline** (priority-ordered).
- A store-scoped order (`X-Store-Code`) auto-submits to `pending` and emits
  `trade.order.created.v1`; plain orders stay `draft` with no outbox.

## 2. Pricing Pipeline

Pricing is computed by a chain of calculators tagged `trade.price_calculator`,
ordered by `getPriority()`. The pipeline produces unit prices, line amounts, and totals.

| Calculator | Priority | Purpose |
|-----------|----------|---------|
| `BasePriceCalculator` | `-100` | Base unit price from specification (via catalog resolver, store-aware) |
| `QuantityCalculator` | `50` | Line amount = unit price × quantity |
| `TotalAggregator` | `55` | Order subtotal before order-level adjustments |
| `PromotionCalculator` | `60` | Item-phase then order-phase promotions (+ best-price selection) |

## 3. Order State Machine

Defined in `config/packages/workflow.yaml` (`state_machine.order`, marking on
`Order::status`):

```
draft → pending → confirmed → paid → fulfilled → completed
  ↘ cancelled (from draft / pending / confirmed only)
paid → refunded (paid only; fulfilled/completed cannot directly refund)
```

Transitions are enforced by Symfony Workflow. Use the transitions endpoint to discover
available moves. Guards:

- Only `draft` orders can be updated/deleted.
- Payment starts only from `confirmed` (`POST /api/v1/app/orders/{id}/payment`).
- Fulfill requires `paid`; refund requires `paid` + `reason` (`systemWalletId`
  required for non-invoice orders).
- Completion guard: orders created with `X-Store-Code` while the Store had
  `settings.fulfillment.requireVerification=true` carry
  `metadata._completionMode=store_verification`; manual `do/complete` is then
  blocked (`Store verification is required`) and completion happens automatically
  after `POST /api/v1/store/{storeUuid}/orders/{orderUuid}/verify` emits
  `store.order.verified.v1` (if verification arrived before fulfillment, the
  order auto-completes right after `fulfill`).

## 4. Trade Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active products |
| GET | `/api/v1/app/specifications` | Browse active specs |
| GET | `/api/v1/app/specifications/by-product/{productId}` | Specs for one product |
| GET | `/api/v1/app/orders` | List user's orders |
| POST | `/api/v1/app/orders` | Create order (with pricing; `202` store-scoped per smoke script, `201` otherwise — see open questions) |
| POST | `/api/v1/app/orders/quote` | Price preview |
| GET | `/api/v1/app/orders/{id}/items` | Own order items |
| POST | `/api/v1/app/orders/{id}/submit` | Submit own order |
| POST | `/api/v1/app/orders/{id}/confirm` | Confirm own order |
| POST | `/api/v1/app/orders/{id}/cancel` | Cancel own order (draft/pending/confirmed only) |
| POST | `/api/v1/app/orders/{id}/payment` | Start order payment |
| POST | `/api/v1/app/orders/{id}/refund` | Refund own order (`reason` required) |
| GET/POST/PUT/DELETE | `/api/v1/manage/products[/{id}]` | Product CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/specifications[/{id}]` | Specification CRUD |
| POST | `/api/v1/manage/orders` | Create order (with pricing) |
| POST | `/api/v1/manage/orders/quote` | Price preview |
| GET | `/api/v1/manage/orders/{id}/items` | Order items |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition (`submit`, `confirm`, `pay`, `fulfill`, `complete`, `cancel`, `refund`) |
| GET | `/api/v1/manage/orders/{id}/transitions` | Available transitions |
| GET | `/api/v1/manage/orders/todo` | Orders needing action |
| POST | `/api/v1/manage/orders/{id}/fulfill` | Fulfill (`trackingNumber`, `shippingAddress`) |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund (`reason` required, `systemWalletId` for non-invoice orders) |
| POST | `/api/v1/app/orders/{id}/payment` | Start order payment |

## 5. Money Handling

Order amounts are stored in **cents** (integer). Never use floats for money. The pricing
pipeline and payment integration both operate on integer minor units.

## 6. Console Commands

No trade admin commands exist beyond the outbox publisher (verified: only
`src/Trade/Command/PublishOutboxCommand.php` under `src/Trade/Command/`).

| Command | Purpose |
|---------|---------|
| `php bin/console app:trade:outbox:publish --no-interaction` | Publish `trade.order.created.v1` / `trade.order.cancelled.v1` to the `async` transport (unsupported topics are deferred with `+5 minutes`) |
| `php bin/console app:store:outbox:publish --no-interaction` | Publish Store events incl. `store.order.verified.v1` (completes `store_verification` orders) |
| `php bin/console app:inventory:outbox:publish --no-interaction` | Publish inventory outcomes (`confirmed` / `rejected` / `released`) |
| `php bin/console app:inventory:reservations:release-expired --no-interaction` | Release expired confirmed inventory reservations |
| `php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction` | One-shot async relay used by smoke tests |
| `php bin/console app:identity:user:create <email> <username> <password> [--admin] [--role=ROLE_X] [--phone=...] [--phone-verified]` | Create a user; `--role` (`-R`) is repeatable and comma-separated, auto-prefixed with `ROLE_`; `--admin` adds `ROLE_ADMIN` |
| `php bin/console app:authorization:seed` | Seed permissions/roles/field-grants (idempotent) |

The Docker `scheduler` runs the trade/store/inventory (+ settlement) publish
commands every `${OUTBOX_PUBLISH_INTERVAL:-5}` seconds (default `5`).

## 7. Smoke Scripts

| Script | Covers | Prerequisites / expected outcome |
|--------|--------|----------------------------------|
| `scripts/tests/api-smoke.sh` | Full API pass: auth, registration, admin catalog (products + specs via `POST /api/v1/manage/products[/{id}/specifications]`), wallets, browse, orders + price verification (cents) + mock/wallet payment, admin user mgmt, balance + reconcile | `curl`, `python3`, `symfony` CLI; `BASE` default `http://127.0.0.1:8000`; auto-creates `admin@example.com` via `app:identity:user:create ... --admin` if login fails; exits non-zero on any failure |
| `scripts/tests/store-smoke.sh` | Store orchestration: store create + `manager` grant, product/spec, optional inventory recipe when `INVENTORY_ENABLED=1` (default `0`), `X-Store-Code` order create, `app:trade:outbox:publish` → `messenger:consume async` → staff `GET /api/v1/store/{uuid}/orders` shows `accepted` (`awaiting_inventory` first with inventory), `app:store:outbox:publish` → consume → Trade order reflects acceptance | Running server (`BASE` default `http://127.0.0.1:8000`); `curl`, `python3`, `symfony` CLI |
| `scripts/tests/demo-trade-workflow.sh` | Live-server E2E of every Trade path: happy path `draft → pending → confirmed → paid → fulfilled → completed → refunded`, cancel from draft/pending/confirmed, paid-cancel guard, non-draft update/delete guards, pay/fulfill/refund guards, app self-service + cross-user guards, transitions + todo, batch update | Running server (`BASE` default `http://127.0.0.1:8080`); `curl`, `python3`; creates `demo@trade.test` via `app:identity:user:create ... --admin` |
| `scripts/tests/demo-trade-workflow.php` | Same workflow matrix as above but in-process (SQLite `test` env, no HTTP server) | `php` with project vendor; exits non-zero on failed assertions |
| `scripts/tests/inventory-smoke.sh` | Inventory management: material create, `allowNegativeStock` policy, stock adjust, available-quantity assertion | Running server (`BASE` default `http://127.0.0.1:8000`); bootstraps admin the same way |
| `scripts/tests/api-stress.sh` | High-volume wallet-paid trading (`NUM_USERS` × `ORDERS_PER_USER`, default `200` × `150`): price checks, cancel/refund mix, per-user + system balance verification | Running server; admin must already exist |

## 8. Docker Ops

Services in `compose.yaml`: `app` (PHP-FPM), `worker`, `scheduler`, `nginx`,
`database` (MySQL 8), `redis`, `mailer` (Mailpit).

- **worker** consumes the `async` transport:
  `php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-interaction`.
  Trade messages (`TradeOrderCreatedMessage`, `TradeOrderCancelledMessage`,
  `StoreOrderVerifiedMessage`, inventory reservation messages) all route to
  `async` (`config/packages/messenger.yaml`); failures go to the `failed`
  transport (`doctrine://default?queue_name=failed`).
- **scheduler** loops every `${OUTBOX_PUBLISH_INTERVAL:-5}` (default `5` seconds):
  `app:trade:outbox:publish`, `app:store:outbox:publish`,
  `app:inventory:outbox:publish`, `app:inventory:reservations:release-expired`,
  `app:settlement:allocations:requeue-due`, `app:settlement:outbox:publish`.
- **JWT keys** (`docker/app/entrypoint.sh`): reuse existing keys; auto-generate
  2048-bit RSA in non-prod (persisted under mounted `var/`); fail fast in `prod`.
- **Env vars**: `compose.prod.yaml` requires `APP_SECRET`,
  `REFRESH_TOKEN_SECRET`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`. Trade-relevant
  knobs: `INVENTORY_ENABLED` (default `0` — gates reservation paths),
  `OUTBOX_PUBLISH_INTERVAL` (default `5`), `MESSENGER_TRANSPORT_DSN` (default
  `doctrine://default?auto_setup=0`).
- **Healthchecks**: public `GET /health/live` and `GET /health/ready`
  (DB required, Redis optional); `nginx` healthcheck polls `/health/ready`.
- **Metrics**: public `GET /metrics` exposes
  `app_outbox_backlog{topic="trade"}` (unpublished `trade_outbox_message` rows)
  and `app_messenger_failed` (`messenger_messages` where `queue_name='failed'`).

## 9. Seed / Migrate Sequence (fresh deploy affecting store/trade)

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
docker compose exec app php bin/console app:authorization:seed
```

Production adds `-f compose.yaml -f compose.prod.yaml --env-file .env.prod.local`
to each `docker compose` invocation. `doctrine:migrations:status` shows pending
migrations; CI validates the full chain from scratch on MySQL 8.4.

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Order cannot transition | Wrong current state | Check state machine (§3) and `GET /api/v1/manage/orders/{id}/transitions` |
| `Store verification is required` on `do/complete` | Order is `_completionMode=store_verification` | Complete via `POST /api/v1/store/{storeUuid}/orders/{orderUuid}/verify` (after fulfill), not manual `complete` |
| Store order never created | `trade.order.created.v1` not relayed (no `X-Store-Code`, or relay stopped) | Only `X-Store-Code` orders emit the event; run `app:trade:outbox:publish` + `messenger:consume async`; check `app_outbox_backlog{topic="trade"}` at `/metrics` |
| Price wrong | Calculator order or promotion conflict | Review pipeline priorities (§2) and promotion phase / `storeCode` |
| Spec not found | Inactive or deleted, or wrong store scope | Check specification status; store catalog scoping applies via `X-Store-Code` |
| `404 Store is not available` on order create | Unknown/inactive `X-Store-Code` | Verify store code and status |
| `400 Currency mismatch` on order create | Request currency differs from Store currency | Match the Store currency (or omit it) |
| Paid order cannot be cancelled/refunded as expected | Guard: only `draft`/`pending`/`confirmed` cancel; only `paid` refunds | Follow §3 terminal rules; check `transitions` endpoint |
| Failed async messages growing | Handler errors / retries exhausted | Check `app_messenger_failed` at `/metrics`; inspect `messenger_messages` with `queue_name='failed'`; check app logs |

## 11. Checklist Before Going Live

- [ ] Products and specifications created and active.
- [ ] Pricing pipeline calculators ordered correctly (Base −100 → Quantity 50 → Total 55 → Promotion 60).
- [ ] Order state machine transitions verified (incl. `store_verification` completion path).
- [ ] Payment integration configured (see Payment runbook).
- [ ] `worker` + `scheduler` running; `/metrics` backlogs near zero; `/health/ready` returns 200.
- [ ] `app:authorization:seed` applied so `store:order:*` grants resolve.
