# Dynamic Query System Reference

Complete reference for the expression-based dynamic query engine used by the `list()`
method in `BaseServiceReadListTrait` and `RestController::requestProcess()`.

---

## 1. @filter — Expression-based Filtering

### 1.1 Overview

`GET /api/v1/manage/products?@filter=entity.price > 1000 && entity.enabled == true`

The filter expression is parsed by `ExpressionService::buildFilter()` →
`ExpressionDqlParser` → `ExpressionQueryBuilderAssembler`, then injected as a DQL
subquery:

```sql
WHERE entity.id IN (
    SELECT filter_entity.id FROM Product filter_entity WHERE filter_entity.price > 1000
)
```

On DQL compilation failure, the system falls back to in-memory filtering (admin-only;
non-admins get `AccessDeniedHttpException`).

### 1.2 Operators

| Operator | Meaning | Example |
|----------|---------|---------|
| `==` | Equality | `entity.status == "active"` |
| `!=` | Not equal | `entity.status != "deleted"` |
| `>` | Greater than | `entity.price > 1000` |
| `<` | Less than | `entity.quantity < 10` |
| `>=` | Greater or equal | `entity.createdAt >= "2024-01-01"` |
| `<=` | Less or equal | `entity.sortOrder <= 100` |
| `&&`, `and` | Logical AND | `entity.enabled == true && entity.price > 10` |
| `\|\|`, `or` | Logical OR | `entity.status == "active" \|\| entity.status == "draft"` |
| `!` | Logical NOT | `!(entity.deletedAt == null)` |
| `matches` | Regex match | `entity.name matches "/^A.*/"` |
| `+`, `-`, `*`, `/` | Arithmetic | `entity.price * entity.quantity > 10000` |
| `in` | Value in list | `entity.id in [1, 2, 3]` |
| `not in` | Value not in list | `entity.id not in [1, 2]` |
| `~` | Contains (substring) | `entity.name ~ "hello"` |
| `starts with` | Prefix match | `entity.slug starts with "cat-"` |
| `ends with` | Suffix match | `entity.filename ends with ".jpg"` |
| `<=>` (spaceship) | Three-way comparison | For sort expressions |
| `=`, `===` | Strict equality | `entity.id = 5` |

### 1.3 Chained Attributes (Dot-Path Traversal)

Filter across relations using dot notation:

```
entity.category.name == "Electronics"
entity.tags.name in ["new", "sale"]
entity.parent.slug starts with "cat-"
```

The parser auto-generates LEFT JOINs for each path segment (e.g., `entity_category` for
`entity.category`).

### 1.4 Functions

#### Math.*

```php
Math.abs(entity.value)
Math.sqrt(entity.area)
Math.ceil(entity.score)
Math.floor(entity.score)
Math.round(entity.price, 2)
Math.min(entity.x, entity.y)
Math.max(entity.x, entity.y)
Math.pow(entity.base, entity.exp)
Math.log(entity.val)
Math.log10(entity.val)
Math.sin(entity.angle)
Math.cos(entity.angle)
Math.tan(entity.angle)
Math.random(1, 100)
Math.pi()
Math.deg2rad(entity.degrees)
Math.rad2deg(entity.radians)
// and all other static methods on Math class
```

#### ArrayCommon.*

```php
ArrayCommon.count(entity.tags) > 3
ArrayCommon.in_array("new", entity.tags.name)
// filter, map, reduce operate on PHP arrays (in-memory fallback only)
```

#### FilterDateTime.*

```php
FilterDateTime.get("2024-01-01")  // creates DateTime in DQL
FilterDateTime.get("now", new \DateTimeZone("UTC"))
```

### 1.5 NULL Handling

```php
entity.deletedAt == null      // IS NULL
entity.deletedAt != null      // IS NOT NULL
entity.deletedAt == true      // evaluates to false for NULL (SQL NULL semantics)
```

### 1.6 DQL Validation

After parsing, `ExpressionDqlParser::validateFragments($em)` validates:
- All field references exist on the entity's Doctrine metadata
- All relationships are valid for the join paths
- No unknown entities or fields appear in the expression

### 1.7 In-Memory Fallback

When DQL compilation fails (e.g., expressions using `ArrayCommon.filter` or complex
Symfony ExpressionLanguage features), the system falls back to:
1. Execute the query without filtering
2. Filter results in PHP using `LegacyEvaluator::evaluateBool()` with each entity as
   the `entity` variable
3. Sort results with `usort()` if `@sort` is also present

**This fallback is restricted to ROLE_ADMIN** because it loads unfiltered data into memory.

---

## 2. @sort — In-Memory Sorting

`GET /api/v1/manage/products?@sort=x.price > y.price`

Forces in-memory mode. Evaluates the expression with `x` and `y` as the two entities being
compared. Returns `1` or `-1` to `usort()`. **Admin-only** (enforced by
`assertPrivilegedQueryParameters()`).

---

## 3. @order — DQL Sorting

`GET /api/v1/manage/products?@order=price|ASC,createdAt|DESC,name|ASC`

Syntax: `field|ASC` or `field|DESC`, comma-separated for multi-field ordering.

Multi-field example:
```
@order=category.sortOrder|ASC,entity.sortOrder|ASC,entity.name|ASC
```

Dot-paths are auto-converted to LEFT JOIN aliases:
```
@order=category.name|ASC
→ LEFT JOIN entity.category entity_category
→ ORDER BY entity_category.name ASC
```

---

## 4. @dql — Raw DQL Sub-query

`GET /api/v1/manage/products?@dql=SELECT p.id FROM App\Common\Entity\Product p WHERE p.price > 1000`

**Admin-only**. The raw DQL is compiled via `$em->createQuery($subDql)` and injected as:
```sql
WHERE entity.id IN (SELECT p.id FROM ...)
```

Security implications: allows joining across any entity in the database. Always restricted
to `ROLE_ADMIN`.

---

## 5. @select — DQL SELECT Projection

`GET /api/v1/manage/products?@select=entity.id, entity.name, entity.price, entity.category.name as categoryName`

Returns partial data instead of full entities. Security restrictions:

- **Blocked on `App\Identity\*` entities** — cannot project identity data
- **Identity fields blocked**: `user`, `profile`, `password`, `roles`, `email`, `phone`,
  `phoneVerified`, `refreshToken`, `sessionKey`, `rawData`

When `@select` or `@groupBy` is present, returns raw query results (not `QueryBuilder`).

---

## 6. @groupBy — DQL GROUP BY

`GET /api/v1/manage/orders?@groupBy=entity.status&@select=entity.status, COUNT(entity.id) as total`

Works with `@select` for aggregation. Dot-paths auto-joined.

---

## 7. @expands — Nested Eager Loading

`GET /api/v1/manage/products?@expands=["entity.category", "entity.tags", "entity.category.parent"]`

Syntax: JSON array of dot-paths. Each path traverses getters and injects `__metadata`:

```json
{
    "id": 1,
    "name": "Laptop",
    "category": {
        "id": 5,
        "__toString": "Electronics",
        "__metadata": { "id": 5, "name": "Electronics", /* full entity */ },
        "parent": {
            "id": 2,
            "__toString": "All Products",
            "__metadata": { /* full entity */ }
        }
    },
    "tags": [
        { "id": 10, "__toString": "new", "__metadata": { /* full entity */ } }
    ]
}
```

The `__metadata` key contains the full normalized entity, while the parent key shows the
reduced form. `FlatNormalizer` handles displaying both.

---

## 8. @display — Response Shaping

`GET /api/v1/manage/products?@display=complex` (default)

| Value | Behavior |
|-------|----------|
| `complex` | Return collection as-is (serializer handles normalization) |
| `reduce` | Map each entity to `{id, __toString}` (minimal representation) |
| `["name", "price"]` | Extract named fields via getters |
| `{"name": "entity.getName()", "upper": "entity.name ~ 'Laptop'", "sqrt": "Math.sqrt(16)"}` | Evaluate expressions per entity; `entity`, `Math`, `ArrayCommon` in scope |

Expression map example:
```
@display={"name": "entity.name", "isExpensive": "entity.price > 1000", "priceFormatted": "Math.round(entity.price / 100, 2)"}
```

---

## 9. @transform — Field Transformation

`POST /api/v1/contents?@transform={"category": "Service.get({'name': ':value'}).getId()"}`

Used during create/update to resolve referenced entities. Features:

- `:value` placeholder replaced with the raw request value for that field
- `Service.get($criteria)` calls the related entity's service to find the target entity
- `Service.list($criteria)` returns an array of identity wrappers
- `Service.get().getId()` returns the resolved entity ID (for foreign key)
- `entity` variable provides access to the current entity (identity wrapper with `.getId()`)
- `Math` and `ArrayCommon` available in expression scope

Example: creating content with a category name rather than ID:
```json
{
    "title": "My Article",
    "category": "Tutorials"
}
```
`@transform`: `{"category": "Service.get({'name': ':value'}).getId()}`
→ Resolves "Tutorials" to the Category entity with ID 5
→ Sets `content.category = 5` before save

---

## 10. @showDQL — Debug Helper

`GET /api/v1/manage/products?@showDQL=true`

**Dev-only** (throws `AccessDeniedHttpException` in non-dev environments). Throws a
`ValidatorException` containing the fully compiled DQL:

```
DQL: SELECT entity FROM App\Common\Entity\Product entity WHERE entity.id IN(SELECT ...)
```

---

## 11. @hints — Doctrine Query Hints

`GET /api/v1/manage/products?@hints={"doctrine.readOnly": true, "doctrine.fetchAll": true}`

JSON-decoded and applied via `$query->setHint($key, $value)`. **Admin-only**.

---

## 12. page & limit — Pagination

`GET /api/v1/manage/products?page=2&limit=50`

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | `1` | Page number (1-indexed) |
| `limit` | `100` | Items per page |

Paginator metadata in response:

```json
{
    "paginator": {
        "total": 250,
        "page": 2,
        "limit": 50,
        "pages": 5,
        "has_previous": true,
        "has_next": true
    }
}
```

Pagination is applied by `RestController::pagination()`. For `QueryBuilder` input, total
is computed via `DoctrinePaginator`. For arrays, total is `count()`.

---

## 13. Expression Caching

`ExpressionService` uses a PSR-16 `CacheInterface` (optional). When a cache is provided:

- Cache key: `'expr_' . sha1($dataClass . '|' . $filter)`
- Stored value: `{dql: compiledDQL, parameters: [{n: name, v: value}, ...]}`
- On cache hit: recreates `Query` from cached DQL, wraps params in `Parameter` objects

Cache invalidation: SHA1 key changes whenever the entity class or filter expression
changes. No explicit invalidation needed — new filters generate new keys.

---

## 14. Troubleshooting

### Common Filter Errors

| Error | Cause | Fix |
|-------|-------|-----|
| `AccessDeniedHttpException: @filter expressions that require in-memory evaluation are restricted to administrators` | DQL compilation failed and user is not admin | Simplify expression to use only database-compatible operators |
| `AccessDeniedHttpException: @dql is restricted to administrators` | Non-admin used `@dql` | Remove `@dql` or use admin credentials |
| `AccessDeniedHttpException: @select cannot access identity data` | `@select` targeting identity fields or entities | Remove identity fields from projection |
| `ValidatorException: DQL: ...` | `@showDQL` is set | This is informational; remove `@showDQL` to see results |
| Filter returns empty results | Expression too complex or field doesn't exist | Use `@showDQL=true` to inspect compiled DQL |
| `AccessDeniedHttpException: @sort is restricted to administrators` | Non-admin used `@sort` | Use `@order` for database-level sorting instead |

### Performance Tips

1. **Use `@order` over `@sort`**: `@order` generates SQL `ORDER BY`; `@sort` loads all data
   into memory.
2. **Use `@expands` for eager loading**: Prevents N+1 queries when rendering related
   entities.
3. **Use `@select` for view projections**: Returns only needed fields instead of full
   entities.
4. **Combine `@filter` with `@select`/`@groupBy`**: Filters are pushed to the database even
   with projections.
5. **Avoid chain-heavy `@sort` expressions**: Each comparison evaluates PHP expressions.
6. **Expression caching**: Provide a PSR-16 cache to `ExpressionService` to avoid re-parsing
   identical filters.
