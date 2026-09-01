# Module Design Contract

> Rules for evolving modules in the current modular monolith. A module is not a
> deployable service. Service extraction follows the
> [Microservice Transition Contract](microservice-transition.md).

---

## 1. Module Shape

Use only the artifacts required by the module's responsibility. A persistence-backed
CRUD module may use this shape:

```
src/{Module}/
|-- Controller/
|   |-- App/{Entity}Controller.php         # Public read endpoints
|   |-- Manage/{Entity}Controller.php      # Admin CRUD endpoints
|-- Entity/{Entity}.php                    # Doctrine entity
|-- Repository/{Entity}Repository.php      # Data access
|-- Service/{Entity}Service.php            # Business logic
|-- Service/{Entity}ServiceInterface.php   # Service contract
|-- Exception/                             # Module-specific exceptions (optional)
|-- EventListener/                         # Event subscribers (optional)
|-- Command/                               # CLI commands (optional)
|-- Resources/config/                      # DI and routing (optional)
```

---

## 2. File-Level Contracts

### 2.1 Entity Contract

- **Location**: `src/{Module}/Entity/{Name}.php`
- **Namespace**: `App\{Module}\Entity`
- **Must**: Implement `__toString()`, declare `touch()` lifecycle hook, use PHP 8 attributes
- **Must**: Follow the [Data Model Design Contract](data-model.md)
- **Must NOT**: Contain DI or service references
- **May**: Enforce aggregate-local invariants and state transitions when doing so
  prevents invalid persisted state. Cross-aggregate orchestration remains in an
  application service.

### 2.2 Repository Contract

- **Location**: `src/{Module}/Repository/{Name}Repository.php`
- **Namespace**: `App\{Module}\Repository`
- **Must**: Extend `ServiceEntityRepository`
- **Must**: Accept `ManagerRegistry` in constructor
- **May**: Add custom query methods returning entities/arrays/scalars
- **Must NOT**: Return raw `QueryBuilder` (that is the service layer's concern)
- **May**: Declare a `{Name}RepositoryInterface` if consumed by other modules

### 2.3 Service Interface Contract

- **Location**: `src/{Module}/Service/{Name}ServiceInterface.php`
- **Namespace**: `App\{Module}\Service`
- **Must**: Be introduced only for an intentional in-process extension point or
  dependency inversion need
- **May**: Extend `App\Core\Service\BaseServiceInterface` for legacy CRUD modules
- **Should**: Declare use-case-specific methods rather than expose a generic CRUD
  API across module boundaries
- **May**: Add module-specific method signatures

```php
interface CategoryServiceInterface extends BaseServiceInterface
{
    // Module-specific methods go here (optional)
}
```

### 2.4 Service Implementation Contract

- **Location**: `src/{Module}/Service/{Name}Service.php`
- **Namespace**: `App\{Module}\Service`
- **May**: Extend `App\Core\Service\BaseService` for legacy CRUD modules
- **May**: Implement a module interface when one is intentionally exported
- **Should**: Prefer explicit constructor dependencies over `ContainerInterface`
  in new code
- **Should**: Contain all business logic, validation, and transaction management
- **Must NOT**: Access Request directly (use `getCurrentRequest()`)
- **Must NOT**: Return raw HTTP responses (that's the controller's job)

```php
class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        ?ServiceLocatorInterface $locator = null,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator $legacyEvaluator = null
    ) {
        parent::__construct(
            $container,
            Category::class,
            $locator,
            $expressionService,
            $legacyEvaluator
        );
    }
}
```

### 2.5 Controller Contracts

Controllers follow two roles. See the [Controller Design Contract](controller-design.md) for full details.

#### App Controller (Public Read-Only)

- **Location**: `src/{Module}/Controller/App/{Name}Controller.php`
- **Namespace**: `App\{Module}\Controller\App`
- **Must**: Extend `RestController`
- **Must**: Use `ApiView`, `DetailApiViewMixin`, `ListApiViewMixin` traits
- **Must**: Set `$serviceClass` property
- **Should**: Override `commonFilter()` to scope data (e.g., `enabled = true`)
- **Must NOT**: Create, update, or delete entities

#### Manage Controller (Admin CRUD)

- **Location**: `src/{Module}/Controller/Manage/{Name}Controller.php`
- **Namespace**: `App\{Module}\Controller\Manage`
- **Must**: Extend `RestController`
- **Must**: Use `ApiView`, `ListApiViewMixin`, `DetailApiViewMixin`, `CreateApiViewMixin`, `UpdateApiViewMixin`, `DeleteApiViewMixin` traits
- **Must**: Set `$serviceClass` property
- **Must**: Guard with `#[IsGranted('ROLE_ADMIN')]` on the class
- **Should**: Declare `$requiredCreateProperties`, `$acceptedCreateProperties`, `$acceptedUpdateProperties`
- **May**: Override hook methods for custom logic

---

## 3. Module Registration Contract

### 3.1 Route Registration

When the module owns HTTP endpoints, add its controllers to `config/routes.yaml`:

```yaml
api_{module}_app:
  resource:
    path: '../src/{Module}/Controller/App/'
    namespace: App\{Module}\Controller\App
  prefix: /api/v1
  type: attribute

api_{module}_manage:
  resource:
    path: '../src/{Module}/Controller/Manage/'
    namespace: App\{Module}\Controller\Manage
  prefix: /api/v1
  type: attribute
```

### 3.2 DI Registration

If the module needs custom service configuration, create:

```yaml
# src/{Module}/Resources/config/services_{module}.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
  App\{Module}\:
    resource: '../../'
    exclude: '../../{Entity,Exception,Resources}'
```

Import in `config/services.yaml`:
```yaml
imports:
  - { resource: '../src/{Module}/Resources/config/services_{module}.yaml' }
```

Otherwise, autowiring covers everything via the global `App\` resource.

### 3.3 Migration

In the current monolith, create a migration that changes one module's tables where
possible. Do not use this rule to split historical mixed migrations. Extracted
services start with a validated schema baseline and service-local migration history.

```bash
php bin/console make:migration
```

Naming convention: `Version{YYYYMMDD}{HHMMSS}.php`

---

## 4. Module Dependency Rules

### 4.1 Current Monolith Dependencies

| Module | May Depend On |
|--------|--------------|
| Any Business Module | Core (always), other modules through explicit application APIs |
| Identity | Core |
| Core | Nothing (foundational) |

### 4.2 Future Service Restrictions

The following are forbidden across independently deployable services:

- Direct DI calls, including service interfaces.
- Doctrine entity, repository, EntityManager, and database foreign-key access.
- Local integer identifiers as durable references.
- Symfony EventDispatcher events as integration events.
- Shared mutable DTOs or framework HTTP objects in contracts.

Use scalar APIs, versioned integration events, Outbox/Inbox, and service-owned
queues instead. See [Microservice Transition Contract](microservice-transition.md).

### 4.3 Module Isolation Checklist

When creating a new module, verify:

- [ ] Module has no circular dependencies with other modules
- [ ] Module has an explicit owner and responsibility
- [ ] Module imports another module only through a deliberate application API
- [ ] New code avoids cross-module entity and repository access
- [ ] An extraction candidate has scalar identifiers and no cross-service persistence assumptions

---

## 5. Module Testing Contract

Each module needs tests appropriate to its responsibility:

| Test Suite | Location | Coverage Target |
|------------|----------|----------------|
| Entity unit tests | `tests/{Module}/Entity/` | Owned persistence invariants and lifecycle |
| Service/handler tests | `tests/{Module}/Service/` | Owned use cases |
| Integration tests | `tests/{Module}/Integration/` | Owned HTTP, persistence, or messaging boundary |

### 5.1 Test Base Classes

| Test Type | Extends | Purpose |
|-----------|---------|---------|
| Unit test | `PHPUnit\Framework\TestCase` | Pure logic tests |
| Kernel test | `IntegrationKernelTestCase` | With booted kernel, DB access |
| Web test | `IntegrationWebTestCase` | Full HTTP request/response |

### 5.2 Test Database

- Test environment uses SQLite (`var/test.db`)
- Schema is created from Doctrine schema tooling in the current test bootstrap;
  migration-chain validation runs separately in MySQL CI
- Each test method is wrapped in a transaction and rolled back

---

## 6. Exception Contract

Module-specific exceptions MUST:

- **Extend**: `\RuntimeException` or `\Exception`
- **Be named**: Descriptively (`InsufficientFundsException`, `OrderInvalidTransitionException`)
- **Be thrown**: In the Service layer (never in Controller or Entity)
- **Be caught**: In the Controller layer (via mixin try/catch blocks)
- **Be logged**: Via `ExceptionInterceptor` for unhandled exceptions on `/api/*` routes

### 6.1 Exception Response Mapping

Unhandled exceptions caught by `ExceptionInterceptor` return:

```json
{
  "code": "{status_code}",
  "message": "{exception_message}",
  "data": {
    "class": "{exception_class}"
  }
}
```

---

## 7. Configuration Contract

Module-specific configuration (if needed beyond environment variables):

- **Location**: `config/packages/{module}.yaml` OR `src/{Module}/Resources/config/`
- **Environment overrides**: Via `%env(...)%` in YAML, NOT hardcoded
- **Sensitive values**: ALWAYS via environment variables, never in committed files

---

## 8. Module Checklist (New Module Creation)

When adding a new business domain, complete these steps in order:

1. Design entities (YAML or sketch first, then PHP classes)
2. Create Doctrine migration
3. Implement repositories
4. Implement service interface + service class
5. Implement App controllers (read-only)
6. Implement Manage controllers (CRUD)
7. Register routes in `config/routes.yaml`
8. Add OpenAPI `#[OA\*]` attributes to all endpoints
9. Write entity unit tests
10. Write service unit tests
11. Write API integration tests
12. Verify CI passes (90% coverage minimum)
