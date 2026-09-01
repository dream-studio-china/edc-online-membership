# Core Usage Guide

Practical patterns and real code examples from the codebase for building on the Core
framework.

---

## 1. Creating a New Controller

### 1.1 Standard Admin CRUD Controller

From `apps/common/src/Main/Controller/Manage/CategoryController.php`:

```php
<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\CategoryService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/categories', name: 'manage-categories-')]
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    public function __construct(
        protected readonly CategoryService $service
    ) {}
}
```

Key points:
- Route prefix MUST use the convention `{scope}-{resource}-` (e.g., `manage-categories-`)
- Route names follow the pattern `{scope}-{resource}-{action}` for OpenAPI tag detection
- Admin controllers MUST have `#[IsGranted('ROLE_ADMIN')]`
- The service is injected as `$this->service` — do NOT add `$serviceClass` when you inject
  via constructor (it overrides the implicit binding)

### 1.2 Public Read-Only Controller with commonFilter()

```php
#[Route('/app/categories', name: 'app-categories-')]
class CategoryController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly CategoryServiceInterface $service
    ) {}

    protected function commonFilter(): array
    {
        return ['enabled' => true];  // Only show enabled categories
    }
}
```

### 1.3 Controller with Field Processing and After-Create Hooks

Extended controller with `processCreateContent()`, `listFilter()`, `detailProcessor()`,
and `afterCreated()`:

```php
class OrderController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin;

    protected array $requiredCreateProperties = ['items'];
    protected array $acceptedCreateProperties = ['items', 'notes', 'couponCode'];

    public function __construct(
        protected readonly OrderService $service
    ) {}

    protected function commonFilter(): array
    {
        $user = $this->getUser();
        return $user instanceof UserUuidPrincipalInterface
            ? ['ownerUuid' => $user->getUuid()]
            : ['id' => -1];
    }

    protected function listFilter($filter): QueryBuilder|null
    {
        if (is_array($filter)) {
            $filter['deletedAt'] = null; // soft-delete filter
        }
        return $filter;
    }

    protected function detailProcessor(?object $entity): ?object
    {
        if ($entity === null) return null;
        // Eager-load items to prevent N+1
        $entity->getItems()->toArray();
        return $entity;
    }

    protected function processCreateContent(array $content, object $entity): array
    {
        // Validate business rules not expressible in entity attributes
        if (empty($content['items'])) {
            throw new ValidatorException('Order must have at least one item');
        }
        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if ($entity instanceof Order) {
            // Side effect: notify admin
            $this->notificationService->notifyNewOrder($entity);
        }
        return $entity;
    }
}
```

---

## 2. Creating a New Service

From `apps/common/src/Main/Service/CategoryService.php`:

```php
<?php

namespace App\Common\Service;

use App\Common\Entity\Category;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Category> */
class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Category::class);
    }
}
```

That's it. The service inherits `get()`, `list()`, `new()`, `update()`, `remove()`,
`wrapInTransaction()`, and all lazy-getters from the base. Add custom business methods as
needed:

```php
class OrderService extends BaseService
{
    public function pay(Order $order): Order
    {
        return $this->wrapInTransaction(function ($em) use ($order) {
            $walletService = $this->resolveWalletService();
            $walletService->transfer(
                $order->getOwnerUuid(),
                'system',
                $order->getTotalAmountCents(),
                'Order #' . $order->getId()
            );
            $order->setPaidAt(new \DateTime());
            $order->setPaymentMethod('wallet');
            $em->persist($order);
            return $order;
        });
    }

    private function resolveWalletService(): WalletServiceInterface
    {
        return $this->container->get(WalletServiceInterface::class);
    }
}
```

**PHPDoc convention**: Always declare `/** @extends BaseService<\App\YourModule\Entity\YourEntity> */`
for IDE autocomplete and static analysis.

---

## 3. Handling File Uploads

From `apps/common/src/Main/Controller/App/MediaController.php` and `MediaService.php`:

### Controller

```php
class MediaController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, DeleteApiViewMixin;

    public function __construct(
        protected readonly MediaServiceInterface $service
    ) {}

    protected function commonFilter(): array
    {
        $user = $this->getUser();
        return $user instanceof UserUuidPrincipalInterface
            ? ['ownerUuid' => $user->getUuid()]
            : ['id' => -1];
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function uploadAction(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->warning('Uploaded file is required', 400, '', 400);
        }

        try {
            $media = $this->service->createFromUpload(
                $file,
                $request->request->has('storage') ? (string) $request->request->get('storage') : null,
                $request->request->all(),
                $this->uploadOwner(),
            );
        } catch (ValidatorException|\RuntimeException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage() ?: 'Upload failed', 500, '', 500);
        }

        return $this->success($media, 'Uploaded', 201);
    }
}
```

### Service

```php
class MediaService extends BaseService implements MediaServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly MediaStorageRegistry $storageRegistry,
        #[Autowire('%media.storage.default%')] private readonly string $defaultStorage,
        #[Autowire('%media.upload.max_size%')] private readonly int $maxUploadSize,
        #[Autowire('%media.upload.allowed_mime_types%')] private readonly array $allowedMimeTypes,
    ) {
        parent::__construct($container, Media::class);
    }

    public function createFromUpload(
        UploadedFile $file,
        ?string $storage = null,
        array $meta = [],
        ?UserUuidPrincipalInterface $owner = null
    ): Media {
        $this->validateUpload($file);

        $storageName = $storage ?: $this->defaultStorage;
        $driver = $this->storageRegistry->get($storageName);
        // ...
        $path = $driver->store($file, $filename);

        $media = new Media(/* ... */);
        // ...
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }
}
```

**Multipart form fields**: `file` (binary), `storage` (string: `local` or `qiniu`),
`category` (int), `alt`, `title`, `width`, `height`.

**Storage driver selection**: `MediaStorageRegistry` resolves driver by name. Falls back
to `media.storage.default` config (`MEDIA_STORAGE_DEFAULT` env var, default `local`).

---

## 4. Custom Actions (Non-CRUD)

Non-CRUD controller methods follow the same pattern: resolve service → call business method
→ wrap result in `success()` / `warning()`.

### Quote/Pay/Cancel Pattern

```php
#[Route('/{id}/pay', name: 'pay', methods: ['POST'])]
public function payAction(int $id): Response
{
    try {
        $order = $this->service->get(['id' => $id]);
        if (!$order) {
            return $this->warning('Order not found', 404, '', 404);
        }
        $this->service->pay($order);
        return $this->success($order, 'Payment processed');
    } catch (InsufficientFundsException $e) {
        return $this->warning($e->getMessage(), 402, '', 402);
    } catch (OrderInvalidTransitionException $e) {
        return $this->warning($e->getMessage(), 400, '', 400);
    }
}
```

### Workflow Transitions (via WorkflowApiViewMixin)

```php
#[Route('/{id}/do/{transition}', name: 'do-transition', methods: ['POST'])]
public function doTransitionAction(Request $request, $id, $transition): Response
{
    // Automatically handled by WorkflowApiViewMixin:
    //   1. Resolve entity
    //   2. Check workflow->can($entity, $transition)
    //   3. Apply transition in a transaction
    //   4. Optionally update entity with content from request body
}
```

---

## 5. Error Handling

### How Exceptions Reach ExceptionInterceptor

The `ExceptionInterceptor` catches **unhandled** exceptions on `/api/*` routes. Controllers
should catch domain exceptions and convert to `warning()` responses. The interceptor is a
safety net for unexpected errors.

### Returning warning()

```php
// 400 — Validation error
try {
    $entity = $service->update($entity, $content);
} catch (ValidatorException $e) {
    return $this->warning($e->getMessage(), 400, '', 400);
}

// 404 — Not found
if (!$entity) {
    return $this->warning('Entity is not found', 404, '', 404);
}

// 500 — Generic failure
try {
    // something risky
} catch (\Exception $e) {
    return $this->warning($e->getMessage() ?: 'Api error occurred', 500, '', 500);
}
```

### Throwing from Services

Services throw domain exceptions. The controller catch-and-wrap pattern:
- `ValidatorException` → `warning(..., 400)`
- `NotFoundHttpException` → `warning(..., 404)`
- `\Exception` → `warning(..., 500)`

---

## 6. Access Control

### commonFilter() Patterns

```php
// User-scoped (only own data)
protected function commonFilter(): array
{
    $user = $this->getUser();
    return $user instanceof UserUuidPrincipalInterface
        ? ['ownerUuid' => $user->getUuid()]
        : ['id' => -1];  // -1 = never matches
}

// Admin (all data)
#[IsGranted('ROLE_ADMIN')]
protected function commonFilter(): array
{
    return [];
}

// Block-all (controller exposes only custom actions, no mixin routes)
protected function commonFilter(): array
{
    return ['id' => -1];
}
```

### #[IsGranted] Usage

```php
// Class-level: applies to all actions
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController { /* ... */ }

// Method-level: applies to specific action
#[IsGranted('ROLE_ADMIN')]
#[Route('/{id}/status-reset', name: 'reset-status', methods: ['PUT'])]
public function resetMarkingAction($entity): Response { /* ... */ }
```

### Route-Level Security (config/packages/security.yaml)

```yaml
access_control:
    - { path: ^/api/doc, roles: PUBLIC_ACCESS }
    - { path: ^/api/auth, roles: PUBLIC_ACCESS }
    - { path: ^/api/v1/manage, roles: ROLE_ADMIN }
    - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }
```

---

## 7. Security Best Practices

### Safe Select Filtering

The `assertSafeSelect()` method in `BaseServiceReadListTrait` blocks `@select` from
accessing identity fields: `user`, `profile`, `password`, `roles`, `email`, `phone`,
`phoneVerified`, `refreshToken`, `sessionKey`, `rawData`. It also blocks any `@select` on
`App\Identity\*` entities.

### Privileged Query Parameters

`@dql`, `@sort`, `@hints` are restricted to `ROLE_ADMIN`. `@showDQL` requires the `dev`
environment. These are enforced by `assertPrivilegedQueryParameters()`.

### Ownership Filtering

Always override `commonFilter()` in user-facing controllers to prevent data leakage across
users:

```php
protected function commonFilter(): array
{
    $user = $this->getUser();
    return $user instanceof UserUuidPrincipalInterface
        ? ['ownerUuid' => $user->getUuid()]
        : ['id' => -1];
}
```

---

## 8. Transactions

### When to Use wrapInTransaction

Use `wrapInTransaction()` whenever multiple related entities are mutated:

```php
// Multiple mutations in one operation
$this->wrapInTransaction(function ($em) use ($order) {
    $this->createInvoice($order);
    $this->adjustInventory($order);
    $order->setStatus('confirmed');
    $em->persist($order);
    // flush + commit happen automatically
});
```

### Automatic Transaction Wrapping

The CreateApiViewMixin and UpdateApiViewMixin automatically wrap batch operations in a
transaction when `@partial` is NOT set to `true`. When `@partial=true`, each item is
processed independently (silent skip on failure).

---

## 9. Translation

### Adding Translatable Strings

Translation keys live in `translations/messages.{locale}.yaml`. Use human-readable English
as the key:

```yaml
# translations/messages.en.yaml
"Entity is not found": "Entity is not found"
"Order is not in draft status": "Order is not in draft status"

# translations/messages.zh.yaml
"Entity is not found": "实体未找到"
"Order is not in draft status": "订单不是草稿状态"
```

### TranslatorInterface Injection

RestControllers get the translator via setter injection:

```php
protected function warning(
    string $error_msg = self::UNKNOWN_ERROR,
    int $error_code = -1,
    mixed $raw_data = '',
    int $status = 200
): Response
{
    $response = [
        'code' => $error_code,
        'message' => $this->getTranslator()->trans($error_msg),
        'raw_data' => $raw_data,
    ];
    // ...
}
```

### trans() Usage

Use the English key directly in code; the translator handles locale resolution:

```php
throw new ValidatorException('Entity is not found');
// In ExceptionInterceptor: $this->translator->trans($exception->getMessage())
```

---

## 10. API Documentation

### Adding #[OA] Attributes to Endpoints

Each controller action must have OpenAPI attributes:

```php
#[OA\Get(
    tags: ['Orders'],
    summary: 'List all orders',
    description: 'Paginated. Supports @filter, @order, @select.',
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: '@filter', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Paginated order list'),
    ]
)]
#[Route('', name: 'list', methods: ['GET'])]
public function listAction(): Response { /* ... */ }
```

### How OpenApiEnricher Detects Tags

Route name convention: `{scope}-{resource}-{action}`. The listener maps:
- `manage-products-list` → tag `Products`
- `app-orders-create` → tag `Orders`
- `system-entity-list` → tag `System`
- `wechat-miniapp-login` → tag `Wechat`

Generic tags from mixin OA attributes (`List`, `Detail`, `Create`, `Update`, `Delete`,
`Workflow`) are removed and replaced with module tags.
