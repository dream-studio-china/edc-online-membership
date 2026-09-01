# Extracting a Service

This guide covers the end-to-end process of extracting a business module from
the root monolith into its own independently bootable service under `apps/`.

## Pre-Extraction Checklist

Before extraction, the module MUST satisfy these boundary rules from
[Microservice Transition Contract](../design/microservice-transition.md):

- [ ] **No cross-service Doctrine associations** — Remove all FK relationships
  to entities in other services. Replace with UUID references.
- [ ] **No cross-service repository access** — The module does not inject or
  call repositories from other services.
- [ ] **No cross-service transactions** — The module's database operations
  are fully self-contained.
- [ ] **UUID identity** — External references use UUIDs, not local integer PKs.
- [ ] **Outbox for cross-service writes** — State-changing integration events
  are written to an Outbox in the producer's local transaction.
- [ ] **Scalar contracts** — No Doctrine entities, repositories, or Symfony
  requests/responses pass service boundaries.
- [ ] **No shared Doctrine associations** — `Identity\Entity\User` is not
  an ORM target in the module's entity mappings.
- [ ] **Tests exist** — The module has its own test suite with reasonable
  coverage before extraction.

### Known Boundary Risks

| Risk | Description | Resolution Before Extraction |
|------|-------------|------------------------------|
| Shared `User` entity | Other modules have `ManyToOne → User` | Replace with `ownerUuid VARCHAR(36)` (as Wallet did) |
| Synchronous cross-service calls | Trade calls Inventory/Payment directly | Replace with Outbox/Inbox integration events |
| PHP-class Messenger contracts | Legacy `App\*\Message` wrappers | Migrate to neutral carriers in `packages/integration-contracts/` |
| Plugin contracts exposing Doctrine types | Payment/Wechat gateways | Create scalar adapter interfaces |
| Shared EntityManager | Single Doctrine connection | Configure independent database + EM |

## Step 1: Create `apps/{name}/` Skeleton

Create the directory structure:

```bash
mkdir -p apps/{name}/{bin,config/packages,docker,migrations,public,src,tests}
```

### Required Files

#### `apps/{name}/composer.json`

```json
{
    "name": "crud-platform/{name}-app",
    "type": "project",
    "require": {
        "php": ">=8.4",
        "crud-platform/platform-kernel": "@dev",
        "symfony/framework-bundle": "^8.1",
        "doctrine/doctrine-bundle": "^3.0",
        "doctrine/doctrine-migrations-bundle": "^3.0",
        "doctrine/orm": "^3.6"
    },
    "autoload": {
        "psr-4": {
            "App\\{Module}\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\{Module}\\Tests\\": "tests/"
        }
    }
}
```

**Important**: Use `@dev` for platform-kernel. This ensures the monorepo
workspace resolves the local package.

#### `apps/{name}/src/Kernel.php`

```php
<?php

declare(strict_types=1);

namespace App\{Module};

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
```

#### `apps/{name}/bin/console`

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\{Module}\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context) {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    return new Application($kernel);
};
```

#### `apps/{name}/config/bundles.php`

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    // Security bundle only if the app needs auth
    // Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
];
```

#### `apps/{name}/config/services.yaml`

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\{Module}\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Kernel.php'
```

#### `apps/{name}/config/packages/doctrine.yaml`

```yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'
        profiling_collect_backtrace: '%kernel.debug%'
    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true
        mappings:
            {Module}:
                dir: '%kernel.project_dir%/src'
                prefix: 'App\{Module}'
                alias: {module}
```

#### `apps/{name}/config/packages/framework.yaml`

```yaml
framework:
    secret: '%env(APP_SECRET)%'
    session: false
    php_errors:
        log: true

when@test:
    framework:
        test: true
```

#### `apps/{name}/config/routes.yaml`

```yaml
# Route definitions for the app's controllers
```

#### `apps/{name}/.env`

```ini
APP_ENV=dev
APP_SECRET=dev-secret-change-me
DATABASE_URL="mysql://root:password@127.0.0.1:3306/{name}?serverVersion=8.0&charset=utf8mb4"
```

#### `apps/{name}/phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true">
    <php>
        <ini name="display_errors" value="1"/>
        <ini name="error_reporting" value="-1"/>
        <env name="APP_ENV" value="test"/>
        <env name="APP_SECRET" value="test-secret"/>
        <env name="DATABASE_URL" value="sqlite:///var/test.db"/>
        <env name="KERNEL_CLASS" value="App\{Module}\Kernel"/>
    </php>
    <testsuites>
        <testsuite name="{Module} Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

#### `apps/{name}/Dockerfile`

```dockerfile
FROM dunglas/frankenphp:8.4-alpine

RUN install-php-extensions pdo_mysql opcache intl

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .
COPY docker/Caddyfile /etc/caddy/Caddyfile

RUN php bin/console cache:warmup --env=prod
```

#### `apps/{name}/docker/Caddyfile`

```
{
    frankenphp
}

:80 {
    header {
        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        Access-Control-Allow-Headers "Content-Type, Authorization"
    }

    root * /var/www/html/public/
    php_server {
        resolve_root_symlink
    }
    encode gzip
    file_server
}
```

### Install Dependencies

```bash
cd apps/{name}
composer install
```

Verify boot:
```bash
php bin/console about
```

## Step 2: Move Source Code

Move source files from root `src/{Module}/` to `apps/{name}/src/`.

```bash
# Example: Moving Store
git mv src/Store/ apps/store/src/
```

**Preserve namespaces**. The namespace becomes `App\{Module}\*` instead of
`App\{Module}\*`. Update all `namespace` declarations in moved files.

**De-prefix entities** if needed. For example, Store entities were de-prefixed:
- `StoreMembership` → `Membership` (table name kept as `store_membership` via `#[ORM\Table]`)
- `StoreOutboxMessage` → `OutboxMessage` (table name kept as `store_outbox_message`)
- `StoreConsumedEvent` → `InboxMessage`

## Step 3: Create Standalone Migration Baseline

Create a single migration that captures the current schema of all tables owned
by the service. This is the "baseline" migration — it represents the schema at
the point of extraction.

```bash
cd apps/{name}
php bin/console doctrine:migrations:generate
```

Edit the generated migration to contain `CREATE TABLE` statements for all
service-owned tables. This migration must be idempotent-safe (use `CREATE TABLE IF NOT EXISTS`).

### Example (Store baseline)

```php
final class Version20260730000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE store (/* ... */)');
        $this->addSql('CREATE TABLE store_membership (/* ... */)');
        $this->addSql('CREATE TABLE store_order (/* ... */)');
        $this->addSql('CREATE TABLE store_inbox_message (/* ... */)');
        $this->addSql('CREATE TABLE store_outbox_message (/* ... */)');
        $this->addSql('CREATE TABLE store_trade_order_cancellation (/* ... */)');
    }
}
```

## Step 4: Add Root Composer Path Package

Add the new app as a Composer path package so the monolith can load it:

```json
// root composer.json
{
    "repositories": [
        {
            "type": "path",
            "url": "apps/{name}"
        }
    ],
    "require": {
        "crud-platform/{name}-app": "@dev"
    }
}
```

Run:
```bash
composer update crud-platform/{name}-app
```

## Step 5: Update Root Service Wiring

### `config/services.yaml`

Add an exclusion for the app's source directory (it's loaded via its own
composer package):

```yaml
services:
    App\:
        resource: '../src/'
        exclude:
            # ... existing exclusions
            - '../src/{Name}/'  # Now lives in apps/{name}/
```

### `config/routes.yaml`

If the app has routes that were previously loaded via root `routes.yaml`,
add an import for the app's routes:

```yaml
# config/routes.yaml
{name}_routes:
    resource:
        path: '../apps/{name}/config/routes.yaml'
    prefix: /api/v1
```

### `config/packages/doctrine.yaml`

Remove the old entity mapping and add a symlink or reference mapping:

```yaml
doctrine:
    orm:
        mappings:
            {Module}:
                dir: '%kernel.project_dir%/apps/{name}/src'
                prefix: 'App\{Module}'
```

## Step 6: Add Standalone Tests

Create `apps/{name}/tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
```

Move existing tests from root `tests/{Module}/` to `apps/{name}/tests/`:

```bash
git mv tests/{Module}/ apps/{name}/tests/
```

Update test namespace to `App\{Module}\Tests\*`.

Verify tests pass independently:
```bash
cd apps/{name}
php vendor/bin/phpunit
```

## Step 7: Update CI

Add a new job to `.github/workflows/ci.yml`:

```yaml
  test-{name}:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: php-actions/composer@v6
        with:
          php_version: "8.4"
          working_dir: apps/{name}
      - run: cd apps/{name} && vendor/bin/phpunit --coverage-clover ../../build/coverage/{name}.xml
```

## Step 8: Add Docker Compose Service

### `compose.yaml`

```yaml
  {name}-app:
    build:
      context: apps/{name}
      dockerfile: Dockerfile
    restart: unless-stopped
    environment:
      APP_ENV: ${APP_ENV:-prod}
      APP_DEBUG: "0"
      APP_SECRET: ${{NAME}_APP_SECRET:?required}
      DATABASE_URL: "mysql://${NAME}_MYSQL_USER:-app}:${NAME}_MYSQL_PASSWORD:-!ChangeMe!}@{name}-database:3306/${NAME}_MYSQL_DATABASE:-app}?serverVersion=8.0&charset=utf8mb4"
    volumes:
      - ./apps/{name}/var:/var/www/html/var
    depends_on:
      {name}-database:
        condition: service_healthy

  {name}-database:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${NAME}_MYSQL_ROOT_PASSWORD:-root}
      MYSQL_DATABASE: ${NAME}_MYSQL_DATABASE:-app}
      MYSQL_USER: ${NAME}_MYSQL_USER:-app}
      MYSQL_PASSWORD: ${NAME}_MYSQL_PASSWORD:-!ChangeMe!}
    volumes:
      - {name}-db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
```

### `compose.override.yaml` (for dev)

```yaml
  {name}-app:
    environment:
      APP_ENV: dev
      APP_DEBUG: "1"
    volumes:
      - ./apps/{name}:/var/www/html  # Source mount for live reload
    ports:
      - "${NAME}_PORT:-8xxx}:80"
```

## Step 9: Verification Checklist

Run these checks in order. All must pass before extraction is considered
complete.

### 9.1 PHPUnit

```bash
cd apps/{name}
php vendor/bin/phpunit
# All tests must pass
```

### 9.2 Container Lint

```bash
cd apps/{name}
php bin/console lint:container
# Must compile without errors or deprecation warnings
```

### 9.3 PHPStan

```bash
cd apps/{name}
php vendor/bin/phpstan analyze
# Must pass at the app's configured level (target: Level 8)
```

### 9.4 Deptrac (if applicable)

```bash
# Ensure no new cross-module violations
composer deptrac
```

### 9.5 Docker Build

```bash
docker compose build {name}-app
# Must build successfully
```

### 9.6 Migration Smoke

```bash
docker compose up -d {name}-database
docker compose run --rm {name}-app php bin/console doctrine:migrations:migrate --no-interaction
# Must complete without errors
```

### 9.7 HTTP Smoke

```bash
docker compose up -d {name}-app
curl http://localhost:{PORT}/
# Should return a response (200 or 404, not connection refused)
```

### 9.8 Root Test Suite

```bash
php vendor/bin/phpunit
# Root test suite must still pass (service is loaded as path package)
```

## Post-Extraction: Remove Legacy Source

After all verification passes and the service has been running stably in the
monorepo:

1. Remove legacy source from `src/{Module}/` (if not already done via `git mv`)
2. Remove legacy root migrations for the now-extracted tables (keep historical
   baseline; new migrations go in `apps/{name}/migrations/`)
3. Update `deptrac-baseline.yaml` to remove legacy violations
4. Remove any temporary `Bridge/` adapters once Gateway routing is ready

**Do not remove legacy code until the service runs independently in production.**
The monolith remains the production host during transition.

## Common Pitfalls

| Pitfall | Symptom | Fix |
|---------|---------|-----|
| **Shared User FK** | `Doctrine\ORM\Mapping\MappingException` | Replace `ManyToOne User` with `ownerUuid VARCHAR(36)` |
| **Missing autoload** | `Class not found` in monolith | Add path package to root `composer.json` |
| **Namespace mismatch** | `App\{Module}\*` vs `App\{Module}\*` | Update namespace in all moved files |
| **Doctrine mapping collision** | Two `doctrine.yaml` files mapping same namespace | Remove old mapping from root config |
| **Test DB mismatch** | Tests fail on SQLite features | Use `VARCHAR` instead of `ENUM`, test on SQLite |
| **Missing env vars** | Container fails to boot | Add required env vars to `compose.yaml` with `${VAR:?required}` |
| **Port conflict** | `bind: address already in use` | Assign unique port in `compose.yaml` and `compose.override.yaml` |
| **Migration baseline missing** | Fresh DB has no tables | Create standalone baseline migration with all `CREATE TABLE` DDL |
| **Worker/scheduler depends on monolith** | Jobs fail in isolation | Ensure worker transport config is self-contained in app config |
| **Hardcoded monolith paths** | `%kernel.project_dir%/var` points wrong | Use app-relative paths in app config |
