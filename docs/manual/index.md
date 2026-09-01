# Development Manual

Welcome to the CRUD Platform development manual. This guide covers everything you
need to know to understand, extend, and operate the platform — from architecture
overview to detailed API contracts.

## Table of Contents

### Foundation

| Document | Description |
|---|---|
| [Architecture](architecture.md) | Service topology, layer architecture, monorepo layout, extraction status, event-driven integration, key patterns |
| [Getting Started](getting-started.md) | Docker and native PHP environment setup, service ports, verifying the installation |
| [Project Structure](project-structure.md) | Full directory tree, naming conventions, module skeleton pattern, where to put new code |

### Core Framework

| Document | Description |
|---|---|
| [Core Framework](core-framework.md) | Deep dive into RestController, BaseService, View mixins, EventListeners, Serializer, Utils — every class and method documented |
| [Core Usage](core-usage.md) | Practical recipes: creating controllers, services, handling file uploads, custom actions, error handling, access control, transactions, API docs |
| [Query System](query-system.md) | Complete reference for `@filter`, `@sort`, `@order`, `@dql`, `@select`, `@groupBy`, `@expands`, `@display`, `@transform`, and all other query parameters |

### Development Process

| Document | Description |
|---|---|
| [Development Workflow](development-workflow.md) | Branching model, coding standards, PHPStan/Deptrac/Rector, commit conventions, PR checklist, CI pipeline |
| [Testing](testing.md) | Test structure, per-app suites, coverage (91.36%), writing unit and integration tests, CI test jobs |
| [API Contracts](api-contracts.md) | JSON envelope formats, authentication, URL conventions, pagination, error handling, NelmioApiDoc, webhooks |
| [Development Contracts](development-contracts.md) | Layer rules, cross-module communication, service boundaries, naming conventions, code style, performance, security |

### Integration & Data

| Document | Description |
|---|---|
| [Integration Events](integration-events.md) | Outbox/Inbox pattern, envelope structure, 10 neutral carriers, publishing/consuming, correlation tracing, backfill commands |
| [Database & Migrations](database-and-migrations.md) | Doctrine conventions, entity patterns, migration workflow, per-app baselines, schema conventions, UUID identity |

### Operations

| Document | Description |
|---|---|
| [Deployment](deployment.md) | Docker Compose (22 services), dev/prod overlays, environment variables, JWT keys, building images, production migrations |
| [Extracting a Service](extracting-a-service.md) | 9-step extraction guide: pre-extraction rules, creating the app skeleton, moving code, baseline migration, wiring, testing, CI, Docker |
| [Internationalization](i18n.md) | 4-locale translation system, adding keys, locale detection, translation flow |

## Quick Links

- [Architecture overview](architecture.md) — start here for the big picture
- [Core Framework](core-framework.md) — every class and method in the platform kernel
- [Core Usage](core-usage.md) — practical recipes for building features
- [Query System](query-system.md) — master the dynamic query parameters
- [Extracting a Service](extracting-a-service.md) — follow this when splitting a module into an independent app
