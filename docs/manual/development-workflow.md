# Development Workflow

## 1. Branching Model

- **`main`** — Production-ready code. Protected branch. Deployable at any time.
- **`dev`** — Integration branch. Feature branches merge here first.
- **Feature branches** — `feature/{description}` or `fix/{description}`. Created
  from `main` (for hotfixes) or `dev` (for features). Merged via PR.
- **Release branches** — (if used) `release/{version}`, prepared from `dev`.

### Workflow

```bash
git checkout dev
git pull origin dev
git checkout -b feature/my-feature
# ... develop, commit ...
git push origin feature/my-feature
# Create PR to dev on GitHub
# After review and CI green → merge to dev
```

## 2. Coding Standards

### PSR-12

All PHP code follows [PSR-12](https://www.php-fig.org/psr/psr-12/). No custom
deviations.

### `declare(strict_types=1)`

Every PHP file MUST start with:
```php
<?php

declare(strict_types=1);
```

### `final` Classes

Use `final` on classes that are not designed for inheritance. Controllers,
commands, message handlers, and entity classes should typically be `final`.
Services and interfaces are not final (to allow decoration and mocking).

### Type Declarations

- All method parameters and return types must be explicitly typed (PHP 8.4).
- Use `mixed` only where unavoidable (e.g., generic `BaseService::get()`).
- Prefer union types over PHPDoc-only types.
- Use PHPDoc `@template TEntity` for generic service propagation.

### PHPDoc

- Required on all non-private methods.
- `@param`, `@return`, `@throws` must be present and accurate.
- `@extends` / `@implements` required on all generic subclasses (PHPStan Level 8).

## 3. Static Analysis Gates

### PHPStan — Level 8

```bash
# Run PHPStan
composer phpstan

# Configuration: phpstan.dist.neon
# Expected: zero errors in configured scope
```

PHPStan runs at **Level 8** with zero tolerated errors. The scope excludes:
- Optional SDK code (`qiniu/php-sdk`)
- Exception classes (which may intentionally extend without type additions)
- Documented false-positive suppressions

### Deptrac — Architecture Boundaries

```bash
# Run Deptrac
composer deptrac
```

Enforces that:
- `App\Core\*` has no dependency on any business module
- No new cross-module Entity/Repository dependencies are created
- `deptrac-baseline.yaml` records exact legacy source-to-target debt

### Rector — Type Rules

```bash
# Apply type improvements
composer rector:types

# CI dry-run check (must pass)
composer rector:types:check
```

Automates Doctrine Collection/Repository PHPDoc generation and other type
standardization.

### Service Container Lint

```bash
# Check container compiles without errors
composer lint:container

# Per-app:
cd apps/store && php bin/console lint:container
```

## 4. Commit Message Conventions

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

| Type | Usage |
|------|-------|
| `feat` | New feature |
| `fix` | Bug fix |
| `refactor` | Code change that neither fixes a bug nor adds a feature |
| `test` | Adding or updating tests |
| `docs` | Documentation changes |
| `chore` | Build, CI, dependencies, tooling |
| `style` | Formatting, whitespace |
| `perf` | Performance improvement |

| Scope | Usage |
|-------|-------|
| `core` | `packages/platform-kernel/` changes |
| `common`, `identity`, `trade`, `store`, `inventory`, `payment`, `wallet` | Per-service changes |
| `ci` | GitHub Actions workflows |
| `docker` | Docker config changes |
| `integration` | Integration contracts changes |
| `i18n` | Translation changes |

### Examples

```
feat(store): add StoreDirectoryOutboxListener for trade_store_directory projection
fix(payment): handle null payer in Invoice notification
test(trade): add OrderService pricing calculator integration tests
chore(ci): add per-app PHPUnit suite jobs
refactor(wallet): remove legacy user_id FK, use ownerUuid only
```

## 5. Pull Request Checklist

Before creating a PR, verify:

- [ ] All tests pass: `composer test` (root) + per-app `composer test`
- [ ] PHPStan Level 8: `composer phpstan` — zero errors
- [ ] Deptrac boundaries: `composer deptrac` — no new violations
- [ ] Rector types check: `composer rector:types:check` — clean
- [ ] Container lint (root + affected apps): `composer lint:container`
- [ ] New code has tests (unit for services, integration for controllers/endpoints)
- [ ] New entities have matching repository + migration
- [ ] New integration events have carrier classes in `packages/integration-contracts/`
- [ ] API changes are reflected in `#[OA\*]` attributes (NelmioApiDoc)
- [ ] Translations added for any new user-facing strings
- [ ] No cross-service Doctrine associations or FKs
- [ ] Commit history is clean (squash where appropriate)

## 6. Code Review Expectations

Reviewers check for:

1. **Correctness** — Does the code do what it claims? Are edge cases handled?
2. **Architecture compliance** — Layer rules (Controller → Service → Repository),
   no cross-service FKs, Outbox for cross-service state changes.
3. **Idempotency** — Integration event consumers must be idempotent by `eventId`.
4. **Transaction boundaries** — State change + Outbox write in the same DB transaction.
5. **Security** — `commonFilter()` scoping, input validation, no secrets in code.
6. **Test coverage** — New logic has tests. Coverage must stay ≥ 90%.
7. **Static analysis** — No new PHPStan/Deptrac/Rector violations.
8. **Code style** — PSR-12, `strict_types`, `final` where appropriate.
9. **Naming** — Follows conventions (see [Project Structure](project-structure.md)).
10. **Documentation** — API doc attributes updated, user-facing strings translated.

## 7. CI Pipeline Overview

The CI pipeline runs on every PR via `.github/workflows/ci.yml`:

### Jobs

| Job | Command | Requirement |
|-----|---------|-------------|
| **PHPUnit — Root Integration** | `composer test` in root | Must pass (963 tests) |
| **PHPUnit — Common** | `composer test` in `apps/common/` | Must pass (74 tests) |
| **PHPUnit — Identity** | `composer test` in `apps/identity/` | Must pass (209 tests) |
| **PHPUnit — Trade** | `composer test` in `apps/trade/` | Must pass (412 tests) |
| **PHPUnit — Wallet** | `composer test` in `apps/wallet/` | Must pass (60 tests) |
| **PHPUnit — Payment** | `composer test` in `apps/payment/` | Must pass (37 tests) |
| **PHPUnit — Store** | `composer test` in `apps/store/` | Must pass (20 tests) |
| **PHPUnit — Inventory** | `composer test` in `apps/inventory/` | Must pass (11 tests) |
| **Coverage Merge** | `phpcov merge` across all suites | Aggregate ≥ 90% |
| **PHPStan** | `composer phpstan` | Zero errors (Level 8) |
| **Deptrac** | `composer deptrac` | No new violations |
| **Rector Types** | `composer rector:types:check` | Clean dry-run |
| **Container Lint** | `composer lint:container` + per-app | Must compile |

### Environment

- CI runs on **PHP 8.4**
- Tests use **SQLite** in-memory (`DATABASE_URL="sqlite:///var/test.db"`)
- No Docker required for test jobs
- Coverage artifacts produced by each PHPUnit job, merged by `phpcov`

### Coverage Gate

```bash
# Merge all coverage artifacts
phpcov merge build/coverage --clover build/coverage/merged.xml

# Check ≥ 90% threshold
# Current: 91.36% aggregate (1785 tests, 6098 assertions)
```

## 8. Local Development Tips

### Running Static Analysis Locally

```bash
# PHPStan
composer phpstan

# Deptrac
composer deptrac

# Rector (apply)
composer rector:types

# Rector (check only)
composer rector:types:check

# Container lint (root)
composer lint:container

# Container lint (all apps)
for app in common identity trade store inventory payment wallet; do
  cd apps/$app && php bin/console lint:container && cd ../..
done
```

### Pre-Commit Checks

Before committing, run the full local check suite:

```bash
# Root
composer phpstan && composer deptrac && composer rector:types:check && composer test && composer lint:container

# Each modified app
cd apps/store && composer phpstan && composer test && php bin/console lint:container
```

### IDE Configuration

- **Psalm / PHPStan plugin**: Use `phpstan.dist.neon` as the project config.
- **PHP CS Fixer**: Follow PSR-12. No custom rulesets.
- **Doctrine**: Enable ORM annotations/attributes support for entity mapping.
