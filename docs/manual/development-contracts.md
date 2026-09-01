# Development Contracts

Development rules, conventions, and quality gates that all contributors MUST follow.
Derived from `docs/design/system-architecture.md`, `docs/design/system-contracts.md`,
and the actual codebase patterns.

---

## 1. Layer Rules

Layer dependency table from `docs/design/system-architecture.md` section 1.1:

| Rule | From | To | Allowed |
|------|------|----|---------|
| R1 | Controller | Service | **YES** |
| R2 | Controller | Entity | **YES** (type hints/returns only) |
| R3 | Controller | Repository | **NO** — go through Service |
| R4 | Controller | EntityManager | **NO** — go through Service |
| R5 | Service | Repository | **YES** |
| R6 | Service | Entity | **YES** |
| R7 | Service | EntityManager | **YES** |
| R8 | Service | Other Services | **YES** (via DI) |
| R9 | Entity | Repository | **NO** |
| R10 | Entity | Service | **NO** |
| R11 | Entity | EntityManager | **NO** |

**Controllers MUST NOT**:
- Inject `EntityManager`
- Call `beginTransaction()`, `commit()`, `rollback()`
- Call Repository directly
- Use `$_GET`, `$_POST`, `$_REQUEST` superglobals
- Contain business logic (delegate to services)

**Services MUST**:
- Own all business logic, transactions, validation
- Use `wrapInTransaction()` for multi-entity mutations
- Return entities or DTOs (never raw database results to controllers)

---

## 2. Cross-Module Communication

### 2.1 Allowed

| From | To | Mechanism |
|------|----|-----------|
| Any Business Module | Core | DI (autowire Core services) |
| Business Module A | Business Module B | DI (autowire B's service **interface**) |
| Any Module | Identity | Current principal abstraction or exported service |

### 2.2 Forbidden

| Pattern | Reason |
|---------|--------|
| Direct cross-module Entity access | Use explicit module API or scalar reference/snapshot |
| Direct cross-module Repository access | Use service interface |
| Circular module dependencies | Violates layer architecture |
| Core importing business modules | Core is foundational |

### 2.3 Cross-Boundary Identity

| Context | Identifier |
|---------|------------|
| Local Doctrine relation inside one module | Integer `id` is allowed |
| Public API route/response | UUID |
| Service interface crossing module boundary | UUID or documented immutable business key |
| Integration event aggregate/source/correlation id | UUID |
| Future service database relation | UUID/business key only; no cross-service FK |

---

## 3. Service Boundary Rules

From `docs/design/microservice-transition.md`, the 7 rules before extraction:

1. A service owns its database and has no cross-service Doctrine association, foreign key,
   repository access, or transaction.
2. Durable cross-service references use UUIDs or documented immutable business keys, never
   another service's local integer primary key.
3. A service boundary accepts scalar commands/queries and emits scalar snapshots. It never
   exposes Doctrine entities, repositories, EntityManagers, Symfony requests/responses, or
   mutable in-process contexts.
4. A state-changing integration event is written to an Outbox in the producer's local
   transaction. Consumers use a durable Inbox keyed by `eventId`.
5. Each consumer owns its queue, retry policy, dead-letter handling, worker, and scheduled
   jobs. A shared Doctrine Messenger queue is not a service boundary.
6. Shared packages may provide framework utilities, schemas, and test support. They MUST NOT
   contain business entities, repositories, or service-specific application logic.
7. Public routes remain stable through an API gateway or routing layer while ownership moves
   between services.

**Pre-Extraction Checklist**:
- [ ] All cross-module references use UUIDs or immutable business keys
- [ ] No shared Doctrine associations or cross-module FK
- [ ] Integration events use Outbox/Inbox pattern
- [ ] Service has its own `composer.json`, `Kernel.php`, `config/`, `migrations/`, `public/`,
  `bin/`, `tests/`, Docker definition
- [ ] Architecture checks (deptrac) pass without new baseline entries
- [ ] Legacy test suite remains green

---

## 4. Namespace Conventions

| Layer | Namespace |
|-------|-----------|
| Core Framework | `App\Core\*` (in `packages/platform-kernel/src/`) |
| Module Controllers | `App\{Module}\Controller\Manage\` or `App\{Module}\Controller\App\` |
| Module Entities | `App\{Module}\Entity\` |
| Module Repositories | `App\{Module}\Repository\` |
| Module Services | `App\{Module}\Service\` |
| Module Exceptions | `App\{Module}\Exception\` |
| Module Event Listeners | `App\{Module}\EventListener\` |
| Module Commands | `App\{Module}\Command\` |
| Bridge Adapters | `App\Bridge\{Module}\` |
| Integration Contracts | `App\Contracts\Integration\` |

Route naming: `{scope}-{resource}-{action}` (e.g., `manage-categories-list`,
`app-orders-create`, `system-router-list`).

---

## 5. File Naming

| Rule | Detail |
|------|--------|
| One class per file | No exceptions |
| File name = class name | `CategoryService.php` contains `class CategoryService` |
| Trait files | Same name as trait |
| Interface files | Same name as interface |
| Tests | `{Class}Test.php` |
| PSR-4 autoloading | Directory mirrors namespace, case-sensitive |

---

## 6. Class Conventions

### 6.1 Required

```php
<?php
declare(strict_types=1);

namespace App\Common\Service;

use App\Common\Entity\Category;
use App\Core\Service\BaseService;

/** @extends BaseService<\App\Common\Entity\Category> */
class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(/* ... */) { /* ... */ }
}
```

- `declare(strict_types=1)` at the top of every PHP file
- `final` where applicable (prevent unintended extension)
- `readonly` for constructor-injected dependencies where possible
- `#[ORM\*]` PHP attributes for Doctrine mapping (not annotations)
- `#[OA\*]` PHP attributes for OpenAPI documentation

### 6.2 PHPDoc

- Required on interfaces and abstract methods (contract documentation)
- `@extends` / `@implements` for generic types on BaseService subclasses
- `@param` / `@return` only when types cannot be expressed in native PHP
- No comments on self-documenting code (well-named methods/variables)

### 6.3 Visibility

- Constructor-injected services: `protected readonly`
- Internal state properties: `private`
- Hook methods: `protected` (overridable)
- Public methods: only on interfaces and action methods

---

## 7. Code Style

### 7.1 PSR-12

Standard code style. Enforced by convention.

### 7.2 PHPStan Level 8

Run `composer phpstan`. Configured over `src/` scope. Level 8 is the strictest level
(excluding Level 9's `mixed` checks for now).

### 7.3 Deptrac Architecture Boundaries

Run `composer deptrac`. Enforces:
- Core MUST NOT depend on a business module
- A module MUST NOT add a dependency on another module's `Entity` or `Repository`

The `deptrac-baseline.yaml` contains reviewed historical violations. New entries require an
explicit architecture decision.

### 7.4 Rector Type Rules

Run `composer rector:types:check` (CI dry-run). Enforces Doctrine `Collection` /
`Repository` PHPDoc generics. Broader Rector (`composer rector`) is opt-in and must be
reviewed.

### 7.5 Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `CategoryService` |
| Methods | camelCase | `getEnabledCategories()` |
| Properties | camelCase | `$serviceClass` |
| Constants | UPPER_SNAKE | `UNKNOWN_ERROR` |
| Interfaces | `*Interface` suffix | `BaseServiceInterface` |
| Abstract classes | `Abstract*` or `Base*` prefix | `BaseService` |
| Traits | `*Trait` suffix or descriptive | `ListApiViewMixin` |
| Tests | `*Test` suffix | `CategoryTest` |

---

## 8. Testing Contracts

### 8.1 Unit vs Integration

| Category | Extends | Purpose |
|----------|---------|---------|
| Unit | `PHPUnit\Framework\TestCase` | Isolated logic (entities, utils, calculators) |
| Kernel | `IntegrationKernelTestCase` | With booted kernel, service access, DB |
| Web | `IntegrationWebTestCase` | Full HTTP request/response cycle |
| Regression | Varies | API contract stability |

### 8.2 Test Naming

| Pattern | Example |
|---------|---------|
| `{Class}Test` | `CategoryTest.php`, `OrderServiceTest.php` |
| `{Module}ApiRegressionTest` | `CommonModulesApiRegressionTest.php` |
| `{Module}IntegrationTest` | `WalletApiIntegrationTest.php` |

Tests in `tests/{Module}/` mirror the `src/{Module}/` structure.

### 8.3 Coverage Requirements

- **Minimum**: 90% line coverage (enforced in CI via PHPUnit)
- **Test environment**: `APP_ENV=test`, SQLite database
- Each test method starts with a clean schema (Doctrine schema tool, not migrations)
- Transactions are wrapped per test and rolled back

### 8.4 Test Database

The test database schema is created from Doctrine schema tool — not from migrations.
`DatabaseBootstrapTrait` handles schema setup. Each test starts with a clean schema.

---

## 9. Git Workflow

### 9.1 Feature Branches

Branch naming: `feature/{description}`, `fix/{description}`, `refactor/{description}`.

### 9.2 Conventional Commits

```
type(scope): description

feat(identity): add phone verification OTP flow
fix(order): prevent double-submission of payments
refactor(core): extract QueryBuilderFactory from BaseService
docs(manual): add API contracts documentation
```

Types: `feat`, `fix`, `refactor`, `docs`, `test`, `ci`, `chore`.

### 9.3 PR Template

Available in `.github/pull_request_template.md`. Must include:
- Summary of changes
- Testing performed
- Breaking changes (if any)
- Checklist (tests pass, static analysis passes, documentation updated)

### 9.4 Review Requirements

- CI must pass (PHPStan Level 8, Rector dry-run, PHPUnit with 90%+ coverage)
- At least one approving review for changes to shared Core classes
- Migration files require explicit review for rollback compatibility

---

## 10. Breaking Changes

### 10.1 What Constitutes a Breaking Change

| Change | Breaking? |
|--------|-----------|
| Change hook method signature | **Yes** — major version bump |
| Remove mixin method | **Yes** — major version bump |
| Change response envelope format | **Yes** — major version bump |
| Remove supported query parameter | **Yes** — deprecation notice + major version bump |
| Change `BaseServiceInterface` | **Yes** — cross-module impact assessment |
| Change entity field type | **Yes** — migration required |
| Add new mixin trait | **No** — documentation only |
| Add new hook method with default impl | **No** — documentation only |
| Add query parameter | **No** — backward compatible |
| Add new module | **No** — follow module contract |

### 10.2 Migration Compatibility

- Schema changes require a backward-compatible migration (old code reads with new schema)
- Irreversible migrations (e.g., column drops) are prohibited without explicit approval
- Migrations organize by module — one migration file per module change set

---

## 11. Documentation Requirements

### 11.1 When Adding a Module

| Required Document | Contents |
|-------------------|----------|
| Design doc (`docs/design/{module}.md`) | Business flow, API design, data model sketch |
| Bundle doc (`docs/design/bundles/{module}.md`) | Module scope, dependencies, exported interfaces |
| OpenAPI spec (code `#[OA\*]`) | Auto-generated at `/api/doc` |

### 11.2 When Adding a Service

- PHPDoc `@extends BaseService<EntityClass>` on the service class
- Service interface in the same namespace
- Register in module's service configuration if not autowired

### 11.3 When Adding an Endpoint

- `#[Route]` attribute with proper `name` following `{scope}-{resource}-{action}` convention
- `#[OA\*]` attributes for NelmioApiDoc
- `#[IsGranted]` for authorization
- Update `OpenApiEnricherListener::META` if endpoint needs custom summary/description

---

## 12. Performance Guidelines

### 12.1 N+1 Prevention

- Use `@expands` to eager-load relations in list endpoints
- Use `detailProcessor()` to call `$entity->getItems()->toArray()` for detail views
- Avoid `@sort` (in-memory) — prefer `@order` (DQL)

### 12.2 Query Optimization

- Use `@select` to project only needed fields
- Use `@filter` to push filtering to the database
- Avoid `@dql` subqueries unless necessary (use `@filter` instead)
- Use Doctrine's `indexBy` in repositories for eager loading

### 12.3 Caching

- ExpressionService can use a PSR-16 cache to avoid re-parsing filters
- `cache.app` is available for application-level caching
- JWT blacklist uses cache (TTL-based)

---

## 13. Security Rules

### 13.1 Safe Select

`@select` is block-listed for identity-sensitive fields (`user`, `profile`, `password`,
`roles`, `email`, `phone`, `phoneVerified`, `refreshToken`, `sessionKey`, `rawData`) and
for any query targeting `App\Identity\*` entities.

### 13.2 Privileged Parameters

`@dql`, `@sort`, `@hints` are restricted to `ROLE_ADMIN`. `@showDQL` requires `dev`
environment.

### 13.3 commonFilter

Every controller with list/detail mixins MUST override `commonFilter()` to enforce
ownership and visibility. For user-scoped endpoints, always return `['id' => -1]` as a
fallback when the user is not authenticated.

### 13.4 CSRF / XSS

- All API authentication uses JWT Bearer tokens (no session, no cookies)
- JSON responses use `Content-Type: application/json`
- No HTML rendering from API endpoints
- Input sanitization is not performed at the framework level; rely on Doctrine parameter
  binding for SQL injection prevention
