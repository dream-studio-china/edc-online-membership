# Store Catalog Model

> **Status: approved target architecture; not implemented yet.** The current Product and
> Specification entities remain in `Trade`. This document defines their planned ownership
> transfer and the invariants that must be implemented before the transfer is considered
> complete.

## 1. Purpose

The catalog supports both independent stores and a lightweight shared catalog without
introducing a `StoreProduct` mapping table prematurely:

```text
Store (optional owner) -> Product -> Specification
```

`Store` is always the place of sale. A Product's nullable Store relation determines
whether the catalog record is shared or private; it never makes the Store optional on
an order.

## 2. Ownership And Visibility

| Product owner | Meaning | Stores allowed to sell it |
|---|---|---|
| `Product.store = NULL` | Shared/global catalog product | Any active Store |
| `Product.store = Store A` | Store-private product | Store A only |

`Specification` belongs to `Product` and inherits its visibility. It does not duplicate
`store_id`.

At every catalog read, quote, and order creation, the server must resolve a Store context
and apply this rule:

```text
product.store IS NULL OR product.store = currentStore
```

A Specification owned by another Store must be rejected even if its UUID is known. List
filters alone are insufficient; the same check is mandatory in pricing/order resolution.

## 3. Checkout And Order Invariants

1. Every new order and quote has a server-resolved, active Store context. A shared product
   does not permit a Store-less order.
2. The selected Specification must be active, not deleted, and visible to the resolved
   Store.
3. The order records the Store snapshot, Product/Specification snapshots, and the scalar
   catalog Specification UUID. Historical payment, fulfillment, refund, and inventory
   flows never re-price or re-scope an order from current catalog state.
4. Inventory remains keyed by `storeUuid + specificationUuid`; a shared Specification can
   therefore have independent stock in each Store.
5. A Product with existing order history must not be moved between global and private
   ownership, or from one Store to another. Create a replacement Product instead.

Payment continues to reference the Trade order and its frozen amount only. It has no
catalog dependency.

## 4. Administration And Authorization

| Operation | Global Product | Store-private Product |
|---|---|---|
| Create/update/delete | Platform administrator | Platform administrator or authorized Store manager |
| Manage Store relation | Platform administrator only | Platform administrator only |
| Read from storefront | Resolved active Store | Owning Store only |
| Quote/order | Any resolved active Store | Owning Store only |

Store-scoped authorization must derive scope from the Product's owning Store. A global
Product is platform-owned; a Store-scoped role must not be able to mutate it.

## 5. Deliberate Limitation

For a shared Product, Specification price, status, title, and attributes are shared too.
Inventory is already Store-specific, but Store-specific price, publication, merchandising,
or text overrides are out of scope for this model.

When a real requirement needs different Store-level price or availability for one shared
catalog record, introduce a separate override/listing aggregate such as:

```text
Catalog Product -> Store Listing -> Store-specific price and availability
```

Do not add that layer until those differing Store-level semantics are required.

## 6. Implementation Migration

1. Add nullable `Product.store_id` with a local Store FK and index; backfill no value so
   existing products remain shared/global.
2. Resolve and require Store context for catalog reads, quotes, and new orders; enforce
   the visibility predicate in the pricing path.
3. Replace `Trade\OrderItem`'s Doctrine Specification relation with a scalar catalog
   Specification UUID plus the existing immutable snapshots. This is required before the
   entity moves because Trade must not retain a cross-module Doctrine association to Store.
4. Move Product and Specification ownership from `Trade` to `Store` in a follow-up
   mechanical refactor. Keep the existing table names and UUIDs initially to avoid a
   data-table rename.
5. Update Store-scoped authorization, tests, OpenAPI descriptions, and event consumers.

The namespace move is not a database migration by itself. The `store_id` relation,
checkout visibility enforcement, and Trade's replacement of the Specification FK are the
behavior-changing parts.
