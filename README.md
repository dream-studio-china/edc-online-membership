# CRUD Skeleton

A production-oriented Symfony + Doctrine CRUD starter with reusable service-layer abstractions.

> Chinese version: see `README.zh-cn.md`

## Table of Contents

- [Quick Start Guide](#quick-start-guide)
- [Why This Project](#why-this-project)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [Run Locally](#run-locally)
- [API Endpoints](#api-endpoints)
- [How the Service Layer Works](#how-the-service-layer-works)
- [Create Your Own CRUD Module](#create-your-own-crud-module)
- [Testing](#testing)
- [Docker Notes](#docker-notes)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Quick Start Guide

For a minimal runnable setup (JWT keys, DB migration, admin user, login/auth test), see [QUICKSTART.md](QUICKSTART.md).

If you are on macOS, commands in the quick start prefer Homebrew PHP 8.5 (`/opt/homebrew/bin/php`) to avoid CLI version mismatch.

## Why This Project

This repository is designed as a clean foundation for backend CRUD development with Symfony.

Compared with plain generated boilerplate, it provides:

- A shared `BaseService` contract for common entity operations.
- Reusable API view mixins (list/detail/create/update/delete).
- A practical pattern for keeping controller logic thin and business logic in services.
- Backward-compatible behavior while modernizing internal implementation.

## Features

- Generic CRUD service methods: `new()`, `get()`, `list()`, `update()`, `updateWithoutListener()`, `remove()`.
- Dynamic list query options through request parameters (ordering/filtering/grouping/select).
- OpenAPI attributes on API mixins for API documentation tooling.
- Unit and integration tests included.
- Docker Compose setup for PostgreSQL and Mailpit.

## Tech Stack

- PHP `>= 8.4`
- Symfony `8.x`
- Doctrine ORM `^3.6`
- PHPUnit `^12.5`
- PostgreSQL (via Docker Compose)

See `composer.json` for the full dependency list.

## Project Structure

```text
.
├── src
│   ├── Common
│   │   ├── Controller
│   │   ├── Entity
│   │   ├── Repository
│   │   └── Service
│   └── Core
│       ├── Controller
│       ├── Service
│       │   ├── Concern
│       │   └── BaseService.php
│       └── View
├── tests
│   ├── Core
│   └── Integration
├── compose.yaml
├── compose.override.yaml
└── phpunit.dist.xml
```

## Getting Started

### 1) Clone

```bash
git clone <your-repo-url>
cd crud-skeleton
```

### 2) Install dependencies

```bash
composer install
```

### 3) Prepare environment

Create your local overrides in `.env.local`:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

## Configuration

Important environment variables (see `.env`):

- `APP_ENV`
- `APP_SECRET`
- `DATABASE_URL`
- `MAILER_DSN`

For production, do not store secrets in committed files.

## Run Locally

### Option A: Native PHP/Symfony

```bash
symfony server:start
```

or

```bash
php -S 127.0.0.1:8000 -t public
```

### Option B: Database with Docker Compose

```bash
docker compose up -d
```

Then run DB schema/migrations using Symfony console:

```bash
php bin/console doctrine:migrations:migrate
```

If no migrations exist yet, you can use schema tools according to your workflow.

## API Endpoints

Current sample module exposes content APIs:

### App scope (read-only style)

- `GET /api/v1/app/contents` - list
- `GET /api/v1/app/contents/{id}` - detail

### Manage scope (CRUD style)

- `GET /api/v1/manage/contents` - list
- `GET /api/v1/manage/contents/{id}` - detail
- `POST /api/v1/manage/contents` - create
- `PUT /api/v1/manage/contents/{id}` - update
- `POST /api/v1/manage/contents/batch-update` - batch update/create mixed mode
- `DELETE /api/v1/manage/contents/{id}` - delete

### Example request

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/manage/contents" \
  -H "Content-Type: application/json" \
  -d '{"title":"Hello","body":"World"}'
```

### Pagination response format

List endpoints return a JSON envelope with `data` and — when pagination is applied — a `paginator` object with canonical metadata. Example:

```json
{
  "data": [ /* items for current page */ ],
  "code": 0,
  "message": "SUCCESS",
  "paginator": {
    "total": 123,
    "page": 2,
    "limit": 10,
    "pages": 13,
    "has_previous": true,
    "has_next": true
  }
}
```

This project no longer depends on the Knp paginator implementation; QueryBuilder-based results use Doctrine's paginator and arrays/collections use array slicing. Controllers extending `App\Core\Controller\RestController` will receive pagination metadata automatically.

## How the Service Layer Works

`BaseService` composes focused traits under `src/Core/Service/Concern`:

- `BaseServiceInfrastructureTrait`
  - EntityManager/repository/logger/serializer access
  - Request stack and validator helpers
  - Expression service and legacy evaluator lazy creation
- `BaseServiceReadListTrait`
  - `get()` and `list()` behavior
  - QueryBuilder-based listing, request-driven filters/order/group/select
- `BaseServiceMutationTrait`
  - `new()`, `update()`, `updateWithoutListener()`, `remove()`
  - Relation/date mapping handling and metadata extraction

Public interface compatibility is preserved through `BaseServiceInterface`.

## Create Your Own CRUD Module

Typical workflow:

1. Create a Doctrine entity in `src/Common/Entity`.
2. Create a service class extending `BaseService`.
3. Create a controller using API mixins from `src/Core/View`.
4. Configure accepted/required input fields in controller properties.

Note on controller construction

Controllers that extend `App\Core\Controller\RestController` do not need to explicitly forward framework-level services (RequestStack, Serializer, Translator) to the parent constructor. Those core dependencies are injected by the service container via setter injection so you can declare only your module-specific constructor arguments (for example the domain service) and keep controllers concise.


Minimal controller example:

```php
<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/app/contents', name: 'app-contents-')]
class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly ContentServiceInterface $service
    ) {}
}
```

Minimal service example:

```php
<?php

namespace App\Common\Service;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentService extends BaseService implements ContentServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Content::class);
    }
}
```

## Testing

Run all tests:

```bash
./vendor/bin/phpunit
```

Run a single test:

```bash
./vendor/bin/phpunit tests/Core/Service/BaseServiceUnitTest.php
```

`phpunit.dist.xml` is preconfigured with `APP_ENV=test` and `KERNEL_CLASS=App\Kernel`.

## Docker Notes

The repository includes:

- `compose.yaml` - PostgreSQL service
- `compose.override.yaml` - host ports + Mailpit

Default exposed ports:

- PostgreSQL: `5432`
- Mailpit SMTP: `1025`
- Mailpit UI: `8025`

## Troubleshooting

### PHPUnit says your PHP version is too old

The project dependencies require modern PHP. Ensure your CLI uses the same major version as in `composer.json` (`>= 8.4`).

### Database connection errors

- Verify `DATABASE_URL`.
- Ensure PostgreSQL is running.
- Ensure DB user/password/dbname match compose environment.

### Empty responses or serialization issues

Check serializer service wiring and request parameters like `@display`, `@expands`, `@filter`.

## Contributing

1. Fork and create a feature branch.
2. Keep pull requests focused.
3. Add/update tests for behavior changes.
4. Use clear commit messages describing the reason for changes.

## License

`composer.json` currently marks this project as `proprietary`.

If you plan to publish publicly, update license metadata and add a dedicated `LICENSE` file.
