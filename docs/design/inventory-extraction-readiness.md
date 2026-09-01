# Inventory Extraction Readiness

> **Inventory source has been fully extracted.** This document records the
> completed transition-host work and the remaining runtime cutover steps.

## Completed

- Inventory source now lives exclusively in `apps/inventory/src` under
  `App\Inventory\*`.
- The monolith temporarily loads it through the `crud-platform/inventory-app`
  Composer path package; root routes, Doctrine mapping, services, PHPStan, Deptrac,
  and Rector point to that package.
- `apps/inventory` has its own Kernel, Composer lock, configuration, PHPUnit smoke
  test, FrankenPHP image, and MySQL 8.4 service.
- The Inventory baseline migration owns nine `inventory_*` tables and contains only
  local Material/Recipe/Reservation foreign keys. Store, Trade, and Specification
  references remain scalar UUIDs.
- The baseline includes nullable correlation and causation Outbox columns. The
  Store-owned cancellation tombstone is intentionally excluded.
- `InventoryServiceInterface` is explicitly aliased to `InventoryService` in both
  the Inventory app and the transitional monolith host.

## Remaining Cutover

1. Keep `INVENTORY_ENABLED=0` outside isolated development and testing until the
   inventory safety checklist is completed.
2. Introduce a shared broker and schema-versioned serializer. Separate Doctrine
   Messenger databases cannot transport Store requests to Inventory or Inventory
   outcomes to Store.
3. Configure an Inventory-owned worker and scheduler for its receiver queue,
   `app:inventory:outbox:publish`, and
   `app:inventory:reservations:release-expired`.
4. Configure Store-owned worker/scheduler and Gateway shadow routing for both apps.
5. After cutover observation, remove the monolith Inventory path-package host,
   Doctrine mapping, route import, and service scan.
6. Drain historical native-PHP queue records before removing
   `packages/legacy-messenger-compat`.
