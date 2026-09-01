# CRUD Platform

CRUD Platform evolves the `crud-skeleton` Symfony backend into a
multi-application microservice platform.

## Current Status

The codebase is currently a **modular monolith** with Store and Inventory extracted
into independently bootable applications:

- One Symfony Kernel for the monolith; Store and Inventory have their own Kernels.
- One Composer project, service container, database, migration chain,
  Messenger queue, worker, scheduler, and Docker image for the monolith.
- Store and Inventory each have config, a MySQL 8.4 database, migration baseline,
  and FrankenPHP container.
- Domain modules include Identity, CMS, Commerce, Store, Inventory, Payment, Wallet,
  Promotion, WeChat adapters, and Storage adapters.
- The `Trade -> Store -> Inventory` Outbox/Inbox flow is the strongest existing
  extraction seam.
- Store source lives exclusively in `apps/store/src` with de-prefixed entities
  (`Membership`, `InboxMessage`, `OutboxMessage`, `TradeOrderCancellation`).
- Inventory source lives exclusively in `apps/inventory/src`; its nine owned
  `inventory_*` tables retain their names and use scalar Store/Trade UUID references.
- Trade resolves `X-Store-Code` through a local `trade_store_directory` projection
  fed by the `store.directory.upserted.v1` event.

This is not yet a set of independently deployed services. Gateway cutover and
standalone worker/scheduler operation are deferred until remaining modules are
extracted and a shared broker replaces the transitional Doctrine transport.

## Target

The target is a monorepo with independently deployable Symfony applications. Each
service owns its Kernel, configuration, database and migrations, queue, worker,
scheduler, image, tests, and CI.

Store and Inventory are extracted transition hosts. Inventory remains disabled by
default and is not production-ready until its safety checklist is complete.

Read the [Microservice Transition Contract](design/microservice-transition.md) for
the target directory structure, service-boundary rules, event envelope, and
extraction gates.

## Local Run

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec inventory-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

- Monolith API: `http://localhost:8080`
- Store API: `http://localhost:8081`
- Inventory API: `http://localhost:8082`
- OpenAPI: `http://localhost:8080/api/doc`
- Mailpit: `http://localhost:8025`

## Documentation

- [Microservice Transition](design/microservice-transition.md)
- [System Architecture](design/system-architecture.md)
- [System Contracts](design/system-contracts.md)
- [Module Design](design/module-design.md)
- [API Design](design/api-design.md)
- [AI Context](ai/context.md)

## Quality Checks

```bash
./vendor/bin/phpunit
composer deptrac
composer phpstan
composer rector:types:check
```

Use PHP 8.4+ for local commands.
