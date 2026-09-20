# Inventory Reservation Phase 1 Design

## Goal

Make reservation classification and cleanup safe without redesigning inventory or repairing physical stock.

## Canonical classification

`ReservationClassificationService` is the sole row-level authority. It returns `state`, `reason`, `recommended_action`, and `would_change_warehouse_stock`. Terminal and protected relationships take precedence. Uncertainty always produces `historical_ambiguous`; missing relationships, age, and null foreign keys are never positive evidence for `legacy_safe`.

The states are `active_valid`, `temporary_active`, `temporary_stale_releasable`, `official_active`, `consumed`, `released`, `legacy_safe`, `historical_ambiguous`, `invoice_linked`, and `invalid_official`.

## Mutation boundaries

Normal release is allowed only for a locked row reclassified as `temporary_stale_releasable`, younger than the legacy threshold. It uses the existing stock-return service and is idempotent.

Legacy cleanup accepts explicit IDs, locks and reloads every row, reclassifies after locking, and closes only `legacy_safe` rows. It updates lifecycle audit fields and rebuilds reserved projections. It never changes warehouse stock or creates stock movements.

Historical ambiguous rows are visible but immutable through reservation management.

## Commands and UI

`reservations:cleanup` owns automatic normal stale release. The old stale command delegates to it or refuses unsafe apply behavior. The legacy command requires `--apply --confirm --ids=...`. A new read-only audit command emits CSV and JSON reports with canonical classifications.

The management controller and views display canonical states and expose actions only for the two explicitly mutable states. Every server mutation revalidates under lock.

## Persistence

No migration is added. Only reservation lifecycle fields and product/product-variant reserved projections may change during legacy cleanup.

