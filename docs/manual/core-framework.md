# Core Framework Reference

Deep-dive into `packages/platform-kernel/src/`. This is the foundation shared library — it
MUST NOT depend on any business module.

---

## 1. RestController

**File**: `packages/platform-kernel/src/Controller/RestController.php`
**Extends**: `Symfony\Bundle\FrameworkBundle\Controller\AbstractController`

Every controller in the platform extends `RestController`. It provides JSON response helpers,
pagination, request processing, service resolution, and setter-injected dependencies.

### 1.1 Constructor & Setter Injection

The constructor accepts optional dependencies. `#[Required]` setters guarantee production
injection while allowing subclasses to omit explicit `parent::__construct()` calls.

```php
class RestController extends AbstractController
{
    private ?RequestStack $requestStack = null;
    private ?SerializerInterface $serializer = null;
    private ?TranslatorInterface $translator = null;
    private ?ContainerInterface $serviceContainer = null;

    public function __construct(
        ?RequestStack $requestStack = null,
        ?SerializerInterface $serializer = null,
        ?TranslatorInterface $translator = null
    ) { /* ... */ }

    #[Required] public function setRequestStack(RequestStack $requestStack): void { /* ... */ }
    #[Required] public function setSerializer(SerializerInterface $serializer): void { /* ... */ }
    #[Required] public function setTranslator(TranslatorInterface $translator): void { /* ... */ }
    #[Required] public function setServiceContainer(ContainerInterface $serviceContainer): void { /* ... */ }
}
```

All four `#[Required]` setters:

| Setter | Dependency | Purpose |
|--------|-----------|---------|
| `setRequestStack()` | `RequestStack` | Access current request, query params |
| `setSerializer()` | `SerializerInterface` | JSON serialization of responses |
| `setTranslator()` | `TranslatorInterface` | Locale-aware message translation |
| `setServiceContainer()` | `ContainerInterface` | Lazy service resolution (`resolveService()`) |

### 1.2 success()

```php
protected function success(
    mixed $content = '',
    string $addition_message = 'SUCCESS',
    int $status = 200
): Response
```

The success path runs through: `pagination(content)` → `requestProcess(items)` → serialize & return.

**JSON envelope**:

```json
{
    "data": [],
    "code": 0,
    "message": "SUCCESS"
}
```

When paginated (GET request with `page`/`limit`), the `paginator` key is added:

```json
{
    "data": [],
    "code": 0,
    "message": "SUCCESS",
    "paginator": {
        "total": 42,
        "page": 1,
        "limit": 100,
        "pages": 1,
        "has_previous": false,
        "has_next": false
    }
}
```

If `status === 204`, returns an empty response (used by DeleteApiViewMixin).

### 1.3 warning()

```php
protected function warning(
    string $error_msg = self::UNKNOWN_ERROR,
    int $error_code = -1,
    mixed $raw_data = '',
    int $status = 200
): Response
```

Returns an error envelope. The message goes through `getTranslator()->trans()`:

```json
{
    "code": 404,
    "message": "Entity is not found",
    "raw_data": ""
}
```

Default status is 200 so the client sees both an HTTP 200 and an application-level error
code. Controllers may override `$status` (e.g., `400`, `404`, `500`).

### 1.4 pagination()

Accepts a `QueryBuilder`, `array`, or `ArrayCollection`. For QueryBuilders, uses
`DoctrinePaginator` to calculate `$total` before setting `setFirstResult()`/`setMaxResults()`.
For arrays/collections, uses `array_slice()`.

Default page limit is 100. Paginator metadata: `total`, `page`, `limit`, `pages`,
`has_previous`, `has_next`.

### 1.5 @expands Processing

The `requestProcess()` method reads `?@expands=['entity.category', 'entity.tags']` from the
query string. For each dot-path, it traverses getters and injects `__metadata` on related
objects so the serializer can include nested data.

### 1.6 @display Processing

Also in `requestProcess()`:

| Value | Behavior |
|-------|----------|
| `complex` (default) | Return the collection as-is (serializer handles it) |
| `reduce` | Map each entity to `{id, __toString}` |
| `["name", "slug"]` (JSON array) | Extract named fields from each entity |
| `{"name": "entity.getName()", "count": "Math.sqrt(16)"}` (JSON object) | Evaluate expressions per entity with `entity`, `Math`, `ArrayCommon` in scope |

### 1.7 getService() / resolveService()

- **`getService()`**: Reads `$this->service` property via reflection, throws if not an object.
- **`resolveService(string $id)`**: Fetches from `$this->serviceContainer`, throws if unavailable.
  Used by `TransformContent` to resolve related entity services.

---

## 2. BaseService & Traits

**File**: `packages/platform-kernel/src/Service/BaseService.php`
**Implements**: `BaseServiceInterface`
**Template**: `@template TEntity of object`

### 2.1 BaseService Constructor

```php
public function __construct(
    ContainerInterface $container,
    string $entityClass,                         // e.g., Category::class
    ?ServiceLocatorInterface $locator = null,
    ?ExpressionServiceInterface $expressionService = null,
    ?LegacyEvaluator $legacyEvaluator = null
)
```

On construction:
1. Resolves `EntityManager` and repository via `ServiceLocatorInterface`
2. Sets `$this->user` from `TokenStorage` (null for unauthenticated requests)
3. Stores optional `ExpressionService` / `LegacyEvaluator` references

Properties:
- `$container` — Symfony DI container
- `$em` — EntityManager
- `$rep` — Doctrine repository for `$entityClass`
- `$entityClass` — The managed entity FQCN
- `$logger` — PSR logger
- `$user` — Current authenticated user or null

### 2.2 BaseServiceInfrastructureTrait

**File**: `packages/platform-kernel/src/Service/Concern/BaseServiceInfrastructureTrait.php`

| Method | Description |
|--------|-------------|
| `wrapInTransaction(callable $fn)` | Begin Tx → execute → flush → commit. On `\Throwable`: rollback + re-throw. Falls back to plain execution if EM lacks transaction methods (test fakes). |
| `getEntityManager()` | Lazy resolve from `$this->em` or `$this->container` |
| `getRepository(?string $class)` | Returns `EntityManager::getRepository($class)` |
| `getLogger()` | Lazy resolve, returns `NullLogger` if unavailable |
| `getSerializer()` | Lazy resolve from `ServiceLocator`, then `$this->container`, then creates a plain `ObjectNormalizer`+`JsonEncoder` |
| `getValidator()` | Returns Symfony validator if available |
| `getRequestStack()` / `getCurrentRequest()` | Access current HTTP request |
| `getQueryBuilderFactory()` | Creates `QueryBuilderFactory` on demand |
| `getExpressionService()` | Creates `ExpressionService` on demand |
| `getLegacyEvaluator()` | Creates `LegacyEvaluator` on demand |
| `externalExpressionValues()` | Returns `['math' => Math, 'datetime' => FilterDateTime, 'Math' => Math, 'Datetime' => FilterDateTime, 'ArrayCommon' => ArrayCommon]` |
| `listResultToCollection()` | Converts `QueryBuilder` or `array` to `ArrayCollection` |

### 2.3 BaseServiceReadListTrait

**File**: `packages/platform-kernel/src/Service/Concern/BaseServiceReadListTrait.php`

#### get()

```php
public function get(mixed $object, bool $directly = false)
```

Resolution logic:
```
- null                     → null
- QueryBuilder             → getSingleResult()
- object with getId()      → $this->rep->find($entityId)
- array<string, mixed>     → $this->rep->findOneBy($array)
- string (valid UUID)      → $this->rep->findOneBy(['uuid' => $string]) if entity has UUID field
- int|string (fallback)    → $this->rep->find($object)
```

#### list()

```php
public function list(mixed $object = null, mixed $order = null, bool $disableRequest = true): mixed
```

When `$disableRequest` is `false`, the method processes these query parameters from the
current request:

- **`@filter`**: Parsed via `ExpressionService::buildFilter()` → DQL subquery → `EXISTS` in clause.
  On parse failure: if admin, falls back to in-memory filtering + usort; if non-admin, throws
  `AccessDeniedHttpException`.
- **`@dql`**: Raw DQL injected as `IN` sub-query. Admin-only (guarded by
  `assertPrivilegedQueryParameters()`).
- **`@order`**: Syntax `field|ASC, field2|DESC`. Converts dot-paths to join aliases
  automatically.
- **`@select`**: DQL SELECT projection. Blocked if it touches `user|profile|password|roles|email|phone|phoneVerified|refreshToken|sessionKey|rawData`
  or targets `App\Identity\*` entities.
- **`@groupBy`**: DQL GROUP BY clause.
- **`@hints`**: JSON-decoded Doctrine query hints. Admin-only.
- **`@sort`**: Expression-based `usort()` with `x` and `y` objects. Triggers fallback to
  in-memory mode. Admin-only.
- **`@showDQL`**: Dev-only. Throws `ValidatorException` containing compiled DQL.

**Safe Select Guard**: `assertSafeSelect()` blocks projections that access identity fields
(see `$identityFields` regex). Any `@select` targeting `App\Identity\*` entities is denied.

**Privileged Parameter Guard**: `assertPrivilegedQueryParameters()` restricts `@dql`, `@sort`,
`@hints` to `ROLE_ADMIN`. `@showDQL` requires `dev` environment.

### 2.4 BaseServiceMutationTrait

**File**: `packages/platform-kernel/src/Service/Concern/BaseServiceMutationTrait.php`

#### new()

Creates a new instance of `$this->entityClass`. If the constructor has no required params,
uses `newInstance()`. Otherwise, uses `newInstanceWithoutConstructor()`.

#### update()

**Pipeline**:

```
1. Validate object not null
2. Re-fetch from DB if entity has an ID (prevents detached-entity issues)
3. For each key in $data:
   a. Check property exists
   b. If #[ManyToOne]/#[OneToOne]: resolve target entity, call setter, unset from $data
   c. If #[ManyToMany]/#[OneToMany]: diff current vs new IDs, call add*/remove* (using Inflect::singularize), unset
   d. If date-like mapping (ORM type datetime/date/time or property type \DateTimeInterface): create DateTime, call setter, unset
4. Deserialize remaining $data onto the object (using Serializer with object_to_populate)
5. Validate the entity (Symfony Validator)
6. Persist + flush
7. Return the entity (or false on reflection error)
```

**Relationship Handling**: ManyToOne/OneToOne sets the other entity via repository find.
ManyToMany/OneToMany computes diffs (added vs removed IDs) and calls the adder/remover.

**DateTime Conversion**: Detected via either ORM attribute `type` or property PHP type
`\DateTimeInterface`. Converts scalar values to `new \DateTime($val)`.

**remove()**: Calls `get()` to resolve, then `$em->remove()` + `$em->flush()`. Returns bool.

---

## 3. View Mixins

All mixins live in `packages/platform-kernel/src/View/`. They compose controllers via
`trait` usage rather than deep class hierarchies.

### 3.1 ApiView (Mandatory Base Trait)

**Trait**: `App\Core\View\ApiView`

Every controller **MUST** `use ApiView`. It provides:

| Property/Method | Purpose |
|-----------------|---------|
| `$serviceClass` | The FQCN of the corresponding service |
| `commonFilter()` | Override to apply ownership/scoping filters. Returns `array` or `QueryBuilder` |
| `mixIdToCommonFilter($id)` | Prepends `['id' => $id]` (or `['uuid' => $id]`) to commonFilter |
| `mixToCommonFilter($data)` | Merges `$data` into commonFilter result |
| `entityNotFoundMessage()` | Returns 'Entity not found' (can be overridden) |

`commonFilter()` scoping patterns:
```php
// User-scoped: only user's own data
protected function commonFilter(): array { return ['ownerUuid' => $this->getUser()->getUuid()]; }

// Admin: no filter (everything visible)
protected function commonFilter(): array { return []; }

// Block-all: prevents access (used when controller should only expose custom actions)
protected function commonFilter(): array { return ['id' => -1]; }

// Public: filter for published/enabled items
protected function commonFilter(): array { return ['enabled' => true]; }
```

### 3.2 ListApiViewMixin

**Route**: `GET /` → `listAction()`

| Hook | When Called | Purpose |
|------|-------------|---------|
| `listFilter($filter)` | Before `service.list()` | Modify filter criteria |
| `listProcessor($entities)` | After `service.list()` | Transform result set |
| `listResponses($entities)` | Before serialization | Final response shaping |

OpenAPI parameters documented: `page`, `limit`, `@order`, `@dql`, `@select`, `@groupBy`,
`@hints`, `@filter`, `@sort`, `@expands`, `@display`, `@showDQL`.

### 3.3 DetailApiViewMixin

**Route**: `GET /{id}` (supports `\d+` and UUID `[0-9a-fA-F-]{36}`)

| Hook | When Called | Purpose |
|------|-------------|---------|
| `detailFilter($filter)` | After `mixIdToCommonFilter`, before `get()` | Modify lookup criteria |
| `detailProcessor($entity)` | After `service.get()` | Transform entity |
| `detailResponse($entity)` | Before serialization | Response shaping |

### 3.4 CreateApiViewMixin

**Route**: `POST /` → `createAction()`

| Property | Type | Purpose |
|----------|------|---------|
| `$requiredCreateProperties` | `string[]` | Fields that MUST be present |
| `$acceptedCreateProperties` | `string[]` | Fields that MAY be present |

Query params: `@partial` (skip transaction), `@transform` (expression transformer).

Input modes:
- `{}` (object): single create, returns entity directly
- `[{}, {}]` (array): batch create, wrapped in transaction (unless `@partial=true`)

| Hook | When Called |
|------|-------------|
| `defaultCreateValues()` | Before processing |
| `processCreateContent(array $content, $entity)` | After transform, before save |
| `processEntity($content, $entity)` | After `new()`, before `update()` |
| `afterCreated($entity)` | After successful save |

### 3.5 UpdateApiViewMixin

**Routes**:
- `PUT /{id}` → `updateAction()`
- `POST /batch-update` → `batchUpdateAction()`

Query params: `@mode=mixed|strict`, `@basis=field1,field2`, `@partial`, `@transform`.

Batch upsert with `@mode=mixed`: attempts to find entity by `@basis` fields. If found →
update; if not found → create.

| Hook | When Called |
|------|-------------|
| `defaultUpdateValues()` | Before processing |
| `processUpdateContent(array $content, $entity)` | After transform, before save |
| `afterUpdated($entity)` | After successful save |

**Compatibility Hooks** (default to update path, override for divergent behavior):
- `defaultValues()` → `defaultUpdateValues()`
- `processContent($content, $entity)` → `processUpdateContent()`
- `after($entity)` → `afterUpdated()`

### 3.6 DeleteApiViewMixin

**Route**: `DELETE /{id}` → `deleteAction()`

| Hook | When Called |
|------|-------------|
| `deletionFilter($filter)` | Before lookup |

Returns 204 (empty body) on success, 404 on failure.

### 3.7 WorkflowApiViewMixin

For entities governed by Symfony Workflow state machines. **Requires** `protected $workflow;`
set to the workflow service ID.

| Route | Action |
|-------|--------|
| `GET /todo` | List entities with available transitions |
| `GET /{id}/transitions` | Get enabled transitions for entity |
| `POST /{id}/do/{transition}` | Execute a workflow transition (wrapped in `wrapInTransaction`) |
| `PUT /{id}/status-reset` | Reset marking (ROLE_ADMIN only) |

### 3.8 SingleCreateAndUpdateApiViewMixin

**Route**: `PUT /` → `updateAction()`

For resources with exactly one row per scope (e.g., user settings). If the entity exists
(via `commonFilter`), it updates. If not, it creates.

Declares both `$requiredCreateProperties` / `$acceptedCreateProperties` and
`$requiredUpdateProperties` / `$acceptedUpdateProperties`, each filtered separately via
`filterCreateProperties()` / `filterUpdateProperties()`.

### 3.9 ScopedDetailApiViewMixin

**Route**: `GET /{id}`

Abstract mixin. Controller must implement:

```php
abstract protected function scopedDetailFilter(string $scopeId, string $id): array|QueryBuilder;
```

The `$scopeId` comes from the URL prefix (e.g., `/{productId}/specifications/{id}`).

### 3.10 ScopedListApiViewMixin

**Route**: `GET /`

Abstract mixin. Controller must implement:

```php
abstract protected function scopedListFilter(string $scopeId): array|QueryBuilder;
```

### 3.11 SingleDetailApiViewMixin

**Route**: `GET /`

Fetches a single resource via `commonFilter()` (no ID in URL).

### 3.12 TransformContent

**Trait**: `App\Core\View\TransformContent`  
**Used by**: `ApiView`

Evaluates `@transform` expressions to resolve field values before save. Example:

```
POST /api/contents with data: {"title": "Hello", "category": "Test"}
@transform: {"category": "Service.get({'name': ':value'}).getId()"}
```

- `:value` is replaced with the raw field value from the request body
- `Service` provides `.get($criteria)` and `.list($criteria)` gateways to the related entity's service
- `Service.get()` returns an identity wrapper with `.getId()` → resolves foreign key
- `entity` is the current entity wrapped in an identity gateway with `.getId()`
- `Math` and `ArrayCommon` are available as expression variables

The transformer detects the related service FQCN by replacing `Entity` with `Service` in
the target entity's namespace, then calls `resolveService()`.

### 3.13 ApiViewMessages

**File**: `platform-kernel/src/View/ApiViewMessages.php`

Centralized message constants used by all mixins:

| Constant / Method | Value / Behavior |
|-------------------|------------------|
| `SUCCESS` | `'SUCCESS'` |
| `ENTITY_NOT_FOUND` | `'Entity is not found'` |
| `INVALID_JSON` | `'Invalid JSON'` |
| `CREATE_FAILED` | `'Create failed'` |
| `BATCH_UPDATE_ERROR` | `'Batch update error'` |
| `CONTENT_TYPE_ERROR` | `'Content type error.'` |
| `TRANSITION_CANNOT_APPLY` | `'Current transition cannot be applied.'` |
| `propertyRequired($prop)` | `'Ucfirst($prop) is required'` |
| `propertyCannotBeEmpty($prop)` | `'Ucfirst($prop) cannot be empty.'` |

---

## 4. EventListeners

### 4.1 ExceptionInterceptor

**File**: `packages/platform-kernel/src/EventListener/ExceptionInterceptor.php`
**Event**: `kernel.exception`

Intercepts exceptions on `/api/*` routes (regex: `/^\/(api)\/.*$/`).

Behavior:
- Logs exception (message + class)
- In `dev.disabled` env: lets Symfony's default error handler handle it
- In production: returns JSON `{code: statusCode, message: translated(message), class: FQCN}`
- Status code: uses `HttpExceptionInterface::getStatusCode()` if available, then falls back
  to `$exception->getCode()` if 400-599, then 500

```json
{
    "code": 500,
    "message": "Api error occurred",
    "class": "RuntimeException"
}
```

### 4.2 ControllerListener

**File**: `packages/platform-kernel/src/EventListener/ControllerListener.php`
**Event**: `kernel.controller`

Logs PUT/POST request bodies (truncated at 1KB) with the authenticated user ID:

```
"User [#42] Requests PUT /api/v1/manage/categories/1: {...body...}"
```

### 4.3 LocaleListener

**File**: `packages/platform-kernel/src/EventListener/LocaleListener.php`
**Event**: `kernel.request`

**Detection Priority**:

1. `?_locale=zh-CN` query parameter (takes precedence)
2. `Accept-Language` header, parsed with quality weights (`q=`)

**Supported locales**: `en`, `zh`, `zh_Hant`, `ja`

**Locale Map** (verbose → canonical):

| Input | Output |
|-------|--------|
| `zh-CN`, `zh-Hans` | `zh` |
| `zh-HK`, `zh-TW`, `zh-Hant`, `zh-Hant-TW` | `zh_Hant` |
| `en-US`, `en-GB` | `en` |
| `ja-JP`, `ja_JP` | `ja` |

Unsupported locales are dropped; Symfony's `default_locale` (`en`) takes effect.

### 4.4 AccessLogListener

**File**: `packages/platform-kernel/src/EventListener/AccessLogListener.php`
**Event**: `kernel.response`

Logs full request/response bodies for POST, PUT, DELETE methods to the `access` log channel
(`monolog.logger.access`).

- Auth paths (`/api/auth`, `/api/wechat`) have bodies hidden
- Non-auth paths: bodies truncated at 4096 characters
- Format: `@username PUT /api/v1/... | 200 | REQ: {...} | RES: {...}`

### 4.5 OpenApiEnricherListener

**File**: `packages/platform-kernel/src/EventListener/OpenApiEnricherListener.php`
**Event**: `kernel.response`

Post-processes `/api/doc.json` and `/api/doc` responses:

- Injects module-specific tags (Products, Orders, Categories, etc.) by parsing `operationId`
  from route names (e.g., `manage-categories-list` → `Categories`)
- Removes generic operation-type tags (`List`, `Detail`, `Create`, `Update`, `Delete`,
  `Workflow`) that come from View mixin OA attributes
- Adds summaries/descriptions from the `META` constant for important endpoints
- Injects `multipart/form-data` request body schema for media upload endpoints

**Tag Detection Logic**:
```
operationId → parse {scope}-{resource}-{action}
  → map known resources (product→Products, order→Orders, etc.)
  → auto-title-case for unknown resources
```

---

## 5. Serializer

### 5.1 FlatNormalizer

**File**: `packages/platform-kernel/src/Serializer/Normalizer/FlatNormalizer.php`
**Implements**: `NormalizerInterface`, `DenormalizerInterface`, `NormalizerAwareInterface`,
`SerializerAwareInterface`

Decorates Symfony's `ObjectNormalizer`. Key transformations:

1. **Doctrine internals** → `__toString()` if available, else class name
2. **`__toString`** added to every serialized entity at top level
3. **Related entities** (objects with `getId()`) → collapsed to `{id, __toString, __metadata}`
4. **Collections** (`Traversable`) → array of reduced relation objects
5. **JSON strings** in fields → auto-parsed to arrays/objects
6. Falls back to minimal representation on normalization failure: `{id, __toString}` or
   `{__class: FQCN}`

**Denormalization**: Delegates to decorated normalizer.

### 5.2 CircularReferenceHandler

**File**: `packages/platform-kernel/src/Serializer/CircularReferenceHandler.php`

Static handler referenced from serializer config. Returns entity ID if available, otherwise
`spl_object_hash()`.

### 5.3 ObjectCallback

**File**: `packages/platform-kernel/src/Serializer/Callbacks/ObjectCallback.php`

Simple callback: calls `getId()` on any object, returns `null` if unavailable.

### 5.4 SerializerContextFactory

**File**: `packages/platform-kernel/src/Serializer/SerializerContextFactory.php`

Builds serializer contexts from options:
- `groups` → `array`
- `max_depth` → `int` with `enable_max_depth` auto-set to `true`

---

## 6. Security Interfaces

### 6.1 UserUuidResolverInterface

```php
interface UserUuidResolverInterface {
    public function resolveUserUuid(int $userId): ?string;
}
```

Resolves a user's UUID from their local integer ID. Production implementation in
`App\Bridge\Identity\IdentityUserUuidResolver`.

### 6.2 UserUuidPrincipalInterface

```php
interface UserUuidPrincipalInterface {
    public function getUuid(): string;
}
```

Implemented by the authenticated user entity. Controllers use this to get the current
user's UUID for ownership filtering:

```php
$user = $this->getUser();
if ($user instanceof UserUuidPrincipalInterface) {
    $uuid = $user->getUuid(); // for commonFilter() ownership scoping
}
```

### 6.3 IdentityProfilePrincipalInterface

```php
interface IdentityProfilePrincipalInterface {
    public function getId(): ?int;
    public function getProfileLevel(): ?string;
}
```

Exposes the user's ID and profile level. Used for authorization decisions.

### 6.4 IdentityUserIdResolverInterface

```php
interface IdentityUserIdResolverInterface {
    public function resolveIdentityUserId(string $userUuid): ?int;
}
```

Resolves a local integer user ID from a UUID. Reverse of `UserUuidResolverInterface`.
Production implementation in `App\Bridge\Identity\IdentityUserIdResolver`.

---

## 7. System Controllers

### 7.1 EntityController

**File**: `packages/platform-kernel/src/Controller/System/EntityController.php`
**Route prefix**: `/system/entities`

| Route | Action |
|-------|--------|
| `GET /system/entities` | List all registered Doctrine entity FQCNs |
| `GET /system/entities/{entityName}` | Field/association metadata for an entity. FQCN with slashes (e.g., `App/Common/Entity/Category`). Returns field mappings (type, nullable, length, etc.), association mappings (type, targetEntity), and auto-generated `plantext`/`translation` per property. |

### 7.2 RouterController

**File**: `packages/platform-kernel/src/Controller/System/RouterController.php`
**Route prefix**: `/system/router`

| Route | Action |
|-------|--------|
| `GET /system/router` | List all registered Symfony routes (from `RouterInterface::getRouteCollection()`) |

---

## 8. Utils

### 8.1 UUID

**File**: `packages/platform-kernel/src/Utils/UUID.php`

| Method | Signature | Description |
|--------|-----------|-------------|
| `v3($namespace, $name)` | `(string, string): string\|false` | MD5-based name-based UUID |
| `v4()` | `(): string` | Random UUID (`mt_rand`-based) |
| `v4c()` | `(): string` | Compact v4 (no dashes) |
| `v5($namespace, $name)` | `(string, string): string\|false` | SHA1-based name-based UUID |
| `is_valid($uuid)` | `(string): bool` | Regex match for UUID format (with/without dashes/braces) |

### 8.2 Math

**File**: `packages/platform-kernel/src/Utils/Math.php`

Full method list: `abs`, `acos`, `acosh`, `asin`, `asinh`, `atan`, `atan2`, `atanh`,
`base_convert`, `bindec`, `ceil`, `cos`, `cosh`, `decbin`, `dechex`, `decoct`, `deg2rad`,
`exp`, `expm1`, `floor`, `fmod`, `getrandmax`, `hexdec`, `hypot`, `is_finite`,
`is_infinite`, `is_nan`, `lcg_value`, `log`, `log10`, `log1p`, `max`, `min`,
`mt_getrandmax`, `mt_rand`, `mt_srand`, `octdec`, `pi`, `pow`, `rad2deg`, `rand`, `round`,
`sin`, `sinh`, `sqrt`, `srand`, `tan`, `tanh`, `random(min, max)`, `locationDistance(lng1, lat1, lng2, lat2)`.

Math constants: `M_E`, `M_EULER`, `M_LNPI`, `M_LN2`, `M_LN10`, `M_LOG2E`, `M_LOG10E`,
`M_PI`, `M_PI_2`, `M_PI_4`, `M_1_PI`, `M_2_PI`, `M_SQRTPI`, `M_2_SQRTPI`, `M_SQRT1_2`,
`M_SQRT2`, `M_SQRT3`.

### 8.3 RSA (RsaClient)

**File**: `packages/platform-kernel/src/Utils/RsaClient.php`

| Method | Description |
|--------|-------------|
| `sign($data)` | Sign data with private key (OPENSSL_ALGO_MD5, base64-encoded) |
| `verifySign($data, $sign)` | Verify signature with public key |
| `rsaSign($params)` | Sign sorted key-value parameter pairs |
| `rsaVerifySign($params, $sign)` | Verify parameter signature |
| `privateEncryptRsa($data)` | Encrypt with private key (PKCS1 padding, chunked) |
| `publicEncryptRsa($data)` | Encrypt with public key (PKCS1 padding, chunked) |
| `privateDecryptRsa($data)` | Decrypt with private key |
| `publicDecryptRsa($data)` | Decrypt with public key |
| `getPrivateKenLen()` / `getPublicKenLen()` | Key length in bits |

Key sources: raw PEM strings (`$rsaPrivateKey`, `$rsaPublicKey`) or file paths
(`$rsaPrivateKeyFilePath`, `$rsaPublicKeyFilePath`).

### 8.4 Inflect

**File**: `packages/platform-kernel/src/Utils/Inflect.php`

| Method | Description |
|--------|-------------|
| `pluralize(string $string): string` | Convert singular to plural (handles irregulars) |
| `singularize(string $string): string` | Convert plural to singular |
| `pluralize_if($count, string $string): string` | "1 item" or "5 items" |

Used internally by `BaseServiceMutationTrait::update()` to derive `addItem`/`removeItem`
from `items`.

### 8.5 ArrayCommon

**File**: `packages/platform-kernel/src/Utils/ArrayCommon.php`

Expression-available array utility:

| Method | Description |
|--------|-------------|
| `in_array($needle, $array)` | Value exists in array |
| `count($array)` | Array element count |
| `merge(...$arrays)` | Merge arrays |
| `push($array, $item)` | Append item |
| `key_exist($key, $array)` | Key exists in array |
| `filter($array, $expression, $external)` | Filter via expression (`value` + external vars) |
| `map($array, $expression, $external)` | Map via expression (`item` + external vars) |
| `reduce($array, $expression, $initial, $external)` | Reduce via expression (`item` + `carry` + external vars) |

### 8.6 FilterDateTime

**File**: `packages/platform-kernel/src/Utils/FilterDateTime.php`

| Method | Description |
|--------|-------------|
| `get($time = 'now', $timezone = null)` | Create `\DateTime` from string |

---

## 9. CoreBundle & DI

### 9.1 CoreBundle

**File**: `packages/platform-kernel/src/CoreBundle.php`

Standard Symfony bundle class (empty). Registered in the consuming app's `config/bundles.php`.

### 9.2 CoreExtension

**File**: `packages/platform-kernel/src/DependencyInjection/CoreExtension.php`

Loads `packages/platform-kernel/src/Resources/config/services.yaml` via YamlFileLoader.
consuming apps extend this with their own extensions.
