# API Contracts

Complete specification of the REST API surface: request/response formats, authentication,
URL conventions, pagination, error handling, documentation, and webhook endpoints.

---

## 1. Request/Response Envelope

### 1.1 Success Envelope

All successful responses use this structure:

```json
{
    "data": {},
    "code": 0,
    "message": "SUCCESS"
}
```

With pagination (GET requests with `page`/`limit`):

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

| Field | Type | Description |
|-------|------|-------------|
| `data` | mixed | Response payload (object, array, or empty string for 204) |
| `code` | int | Always `0` for success |
| `message` | string | Success message (default `"SUCCESS"`) |
| `paginator` | object? | Present only for paginated GET responses |

HTTP status codes for success:
- `200` — standard success
- `201` — created (POST)
- `204` — deleted (no body, `Content-Type: application/json`)

### 1.2 Error Envelope

```json
{
    "code": 404,
    "message": "Entity is not found",
    "raw_data": ""
}
```

| Field | Type | Description |
|-------|------|-------------|
| `code` | int | Application error code (typically HTTP status) |
| `message` | string | Translated error message |
| `raw_data` | mixed | Additional error data (debugging) |

ExceptionInterceptor adds a `class` field for unhandled exceptions:

```json
{
    "code": 500,
    "message": "An error occurred",
    "class": "RuntimeException"
}
```

### 1.3 HTTP Status Codes Used

| Code | Meaning | When |
|------|---------|------|
| 200 | OK | General success; also used for application-level errors (check `code` field) |
| 201 | Created | Successful POST creation |
| 204 | No Content | Successful DELETE |
| 400 | Bad Request | Validation errors (`ValidatorException`), invalid JSON |
| 401 | Unauthorized | Missing or invalid JWT |
| 402 | Payment Required | Insufficient funds |
| 403 | Forbidden | Access denied (non-admin accessing admin endpoints) |
| 404 | Not Found | Entity not found |
| 500 | Internal Server Error | Unhandled exceptions |

---

## 2. Authentication

### 2.1 JWT Bearer Token Format

All authenticated requests include:

```
Authorization: Bearer <access_token>
```

Token specification:
- **Algorithm**: RS256 (asymmetric, public/private key pair)
- **TTL**: 7200 seconds (2 hours)
- **Payload**: user ID, roles, expiration, JTI (unique token ID)

### 2.2 Token Lifecycle

| Operation | Endpoint | Auth |
|-----------|----------|------|
| Login | `POST /api/auth/login` | None (public) |
| OTP Request | `POST /api/auth/otp/request` | None (public) |
| OTP Verify | `POST /api/auth/otp/verify` | None (public) |
| Token Refresh | `POST /api/auth/token/refresh` | None (public; uses refresh token in body) |
| Token Revoke | `POST /api/auth/logout` | Authenticated |
| WeChat Login | `POST /api/wechat/miniapp/login` | None (public) |

**Refresh Token Behavior**:
- Opaque string, HMAC-SHA256 hashed in the database
- 1 year TTL
- Token rotation: replaced on each use
- Reuse detection: if a revoked/replaced refresh token is submitted, ALL user tokens are
  revoked

**JWT Blacklist**:
- On logout or token rotation, the access token's JTI is added to a cache-based blacklist
- TTL matches the token's natural expiration (no permanent storage)
- Blacklisted JTIs rejected at authentication step

### 2.3 Public vs Authenticated Routes

| Route Pattern | Access |
|---------------|--------|
| `/api/doc`, `/api/doc.json` | PUBLIC_ACCESS |
| `/api/auth/*` | PUBLIC_ACCESS |
| `/api/wechat/*` | PUBLIC_ACCESS (WeChat callbacks have signature verification) |
| `/api/v1/app/*` | IS_AUTHENTICATED_FULLY |
| `/api/v1/manage/*` | ROLE_ADMIN |
| `/api/v1/public/*` | PUBLIC_ACCESS |
| `/api/payment/notify/*` | PUBLIC_ACCESS (payment provider callbacks) |

---

## 3. URL Conventions

### 3.1 Prefix

All API routes are prefixed with `/api/v1`. Versioning is in the URL path.

### 3.2 Path Segments

```
/api/v1/{scope}/{resource}[/{id}][/{sub-resource}]
```

| Scope | Purpose | Example |
|-------|---------|---------|
| `manage` | Admin CRUD operations | `/api/v1/manage/categories` |
| `app` | Authenticated user operations | `/api/v1/app/orders` |
| `public` | Anonymous read-only access | `/api/v1/public/media` |

Special non-versioned prefixes:
| Prefix | Purpose |
|--------|---------|
| `/api/auth` | Authentication endpoints |
| `/api/wechat` | WeChat integration endpoints |
| `/api/payment/notify` | Payment provider webhooks |
| `/api/doc` | NelmioApiDoc documentation |
| `/system/entities` | Doctrine metadata introspection |
| `/system/router` | Route listing |

### 3.3 RESTful Patterns

| Method | URL | Action |
|--------|-----|--------|
| `GET` | `/api/v1/manage/{resource}` | List (paginated) |
| `GET` | `/api/v1/manage/{resource}/{id}` | Detail (single entity) |
| `POST` | `/api/v1/manage/{resource}` | Create (single or batch) |
| `PUT` | `/api/v1/manage/{resource}/{id}` | Update (single) |
| `POST` | `/api/v1/manage/{resource}/batch-update` | Batch upsert |
| `DELETE` | `/api/v1/manage/{resource}/{id}` | Delete |

ID format: accepts both integers (`\d+`) and UUIDs (`[0-9a-fA-F-]{36}`).

---

## 4. Pagination

### 4.1 Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | `1` | Page number (1-indexed) |
| `limit` | `100` | Items per page |

Example: `GET /api/v1/manage/products?page=3&limit=25`

### 4.2 Paginator Response Structure

```json
{
    "paginator": {
        "total": 250,
        "page": 3,
        "limit": 25,
        "pages": 10,
        "has_previous": true,
        "has_next": true
    }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `total` | int | Total number of items in the collection |
| `page` | int | Current page number |
| `limit` | int | Items per page |
| `pages` | int | Total number of pages |
| `has_previous` | bool | `true` if page > 1 |
| `has_next` | bool | `true` if page < pages |

Paginator is absent for non-GET requests. `RestController::pagination()` checks
`$request->getMethod() !== 'GET'`.

---

## 5. Error Handling

### 5.1 ExceptionInterceptor Behavior

Intercepts unhandled exceptions on `/api/*` routes:
1. Logs exception (class, message, trace)
2. In dev: lets Symfony's default error page handle it
3. In production: returns JSON `{code, message, class}` with appropriate HTTP status

### 5.2 Specific Exception Handling

| Exception | HTTP Status | JSON `code` | Beacon |
|-----------|-------------|-------------|--------|
| `ValidatorException` | 400 | 400 | Validation failures |
| `NotFoundHttpException` | 404 | 404 | Entity not found |
| `AccessDeniedHttpException` | 403 | 403 | Permission denied |
| `InsufficientFundsException` | 402 | 402 | Wallet balance too low |
| Generic `\Exception` | 500 | 500 | Unexpected errors |

### 5.3 Adding Custom Exceptions

1. Extend `\RuntimeException` in your module's `Exception/` directory
2. Throw from the service layer
3. Catch in the controller and convert to `warning()`:

```php
catch (InsufficientFundsException $e) {
    return $this->warning($e->getMessage(), 402, '', 402);
}
```

---

## 6. Data Formats

### 6.1 JSON Request/Response

All requests and responses use `application/json`. The `RestController::success()` and
`warning()` methods call `$this->getSerializer()->serialize($response, 'json')`.

### 6.2 Multipart File Uploads

Media upload endpoints use `multipart/form-data`:

```
POST /api/v1/app/media/upload
Content-Type: multipart/form-data

file: [binary]
storage: "local"
category: 3
alt: "My photo"
title: "Vacation 2024"
```

### 6.3 Array vs Single-Object Input

Create endpoints accept both:
- **Single object** `{}` → creates one entity, returns entity directly
- **Array** `[{}, {}, {}]` → batch create, wraps in transaction, returns array

Detected by `FixJSON::getJSONType($content)`.

### 6.4 @partial Batch Mode

`POST /api/v1/manage/products?@partial=true`

When `@partial=true`:
- Batch creates/updates skip transaction wrapping
- Failed items are silently skipped
- Successful items are returned

When `@partial=false` (default):
- All items in a single transaction
- Any failure rolls back all items

---

## 7. Translation

### 7.1 Message Key Conventions

Error messages use human-readable English as translation keys. The `warning()` method
routes messages through `$this->getTranslator()->trans($error_msg)`.

### 7.2 Locale Negotiation

The `LocaleListener` on `kernel.request` detects locale in this priority:

1. `?_locale=zh-CN` query parameter
2. `Accept-Language` header with quality weights

Supported locales: `en`, `zh`, `zh_Hant`, `ja`. Unsupported locales fall back to `en`.

---

## 8. Rate Limiting / CORS

### 8.1 Current State

No system-wide rate limiting or CORS headers are implemented at the framework level.
Specific limits:

| Resource | Limit | Implementation |
|----------|-------|---------------|
| OTP request | 1 per 60s per phone | Application-level (Redis/cache) |
| OTP verify | 5 attempts per phone | Application-level (Redis/cache) |

### 8.2 Future Plans

Rate limiting and CORS handling are planned for the API gateway layer during the
microservice transition.

---

## 9. API Versioning

### 9.1 Prefix Strategy

All versioned endpoints use `/api/v1` prefix. The version is in the URL path, not in
headers or content negotiation.

### 9.2 Backward Compatibility Rules

| Change | Allowed | Requires |
|--------|---------|----------|
| Add new endpoint | Yes | Documentation |
| Add optional query parameter | Yes | Backward compatible |
| Add field to response | Yes | Backward compatible |
| Change field type in response | **No** | Major version bump |
| Remove field from response | **No** | Deprecation notice + major version bump |
| Change response envelope format | **No** | Major version bump |
| Remove endpoint | **No** | Deprecation notice + major version bump |
| Change authentication requirements | **No** | Major version bump |

---

## 10. NelmioApiDoc

### 10.1 #[OA] Attributes

All endpoints are documented via PHP 8 attributes. View mixins provide default OA
attributes for standard CRUD operations:

```php
#[OA\Get(
    tags: ['List'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'string')),
        // ...
    ],
    responses: [
        new OA\Response(response: 200, description: 'Api list view'),
    ]
)]
```

### 10.2 Schema Configuration

Schema is configured in `config/routes.yaml`:

```yaml
app.swagger_ui:
    path: /api/doc
    controller: nelmio_api_doc.controller.swagger_ui
```

JSON schema available at `/api/doc.json`.

### 10.3 Tag Auto-Detection

The `OpenApiEnricherListener` post-processes OpenAPI output:
- Extracts resource name from `operationId` (route name)
- Maps to display tags: Products, Orders, Categories, Tags, Contents, Comments, Pages,
  Media, Settings, Payment, Wallet, System, Wechat, Store, Auth
- Removes generic operation-type tags (`List`, `Detail`, `Create`, `Update`, `Delete`,
  `Workflow`)
- Adds summaries/descriptions for key endpoints

### 10.4 Documenting New Endpoints

1. Add `#[OA\*]` attributes on the controller action
2. Set `tags` to match the module's tag name
3. Route name must follow `{scope}-{resource}-{action}` convention for auto-detection:

```php
#[OA\Get(
    tags: ['MyModule'],
    parameters: [],
    responses: [new OA\Response(response: 200, description: '...')]
)]
#[Route('/custom-action', name: 'manage-myresource-custom-action', methods: ['GET'])]
```

---

## 11. Webhook Endpoints

### 11.1 Notification Patterns

Payment gateways and third-party services call back to public webhook endpoints:

```
POST /api/payment/notify/{payment}
```

Where `{payment}` selects the registered payment gateway (e.g., `wechat`, `wallet`,
`mock`).

### 11.2 Public Access

Webhook endpoints are public (no JWT required). Authentication is via provider-specific
signature verification:
- The gateway verifies the callback signature/payload
- `InvoiceService` applies the notification result

### 11.3 Signature Verification

Payment adapters use `RsaClient` or provider-specific signing to verify webhook
authenticity before processing. Invalid signatures result in immediate rejection.
