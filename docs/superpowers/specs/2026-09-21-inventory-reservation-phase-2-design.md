# Inventory and Reservation Authority Phase 2 Design

## Goal

Establish one authoritative physical-stock model and one authoritative active-reservation model while retaining existing projection columns for compatibility. Repair reservation projection drift safely and prevent recurrence without changing reservation physical-stock lifecycle semantics.

## Scope and invariants

- Central physical available stock is the central warehouse variant row in `warehouse_stocks.quantity`.
- Active reserved quantity is the sum returned by `ReservationQueryService::activeQuery()`.
- Controlled physical total is central available plus canonical active reserved quantity.
- `product_variants.stock` and `products.stock` are projections of central available stock.
- `product_variants.reserved` and `products.reserved` are projections of canonical active reservation quantity.
- Reads never create warehouse rows. A missing warehouse row reads as zero.
- A real reservation creation decreases central available exactly once.
- A real reservation release increases central available exactly once.
- Invoice consumption does not return physical stock.
- Legacy cleanup does not return physical stock.
- Phase 2 does not release stale temporary, invalid official, consumed, released, or historical ambiguous reservations.

## Canonical stock access

`WarehouseStockService` remains the normal application boundary. `available()` becomes a side-effect-free query returning zero for a missing row. Explicit mutation methods retain row creation under transaction and lock. Normal workflows must not update stock projections independently before or after calling the service.

Administrative stock-count, import, recovery, and reconciliation commands may remain explicit guarded writers where their semantics intentionally replace or restore warehouse state. They must synchronize stock projections deliberately and must not be mistaken for reservation lifecycle operations.

## Canonical reservation reads

`ReservationQueryService::activeQuery()` remains the sole active-reservation definition. Variant and product totals, business validation, integrity audit R01, stock-count reserved snapshots, and projection rebuilds derive from it. Health-monitoring queries remain separate and cannot feed reserved projections.

## Reservation projection service

A focused `ReservationProjectionService` owns drift reporting and rebuilding. It exposes read-only inspection and transactional rebuild methods. Rebuild uses sorted product and variant identifiers, locks affected products and variants deterministically, locks relevant reservation lifecycle rows deterministically, recomputes canonical totals after all locks are held, then updates projections. Product totals are sums of the freshly computed variant totals.

The service never changes warehouse stock, stock projections, movements, reservation rows, preinvoice rows, or invoices. Repeated rebuilds are idempotent.

## Repair command

`inventory:repair-reserved-cache` becomes a thin auditable command requiring exactly one of `--dry-run` or `--apply --confirm`. Dry-run installs the existing write-query guard and reports affected variants and products with before, expected, and difference values. Apply locks and recalculates through `ReservationProjectionService`; it does not write from a stale preview.

Required summary fields are `variants_checked`, `variants_changed`, `products_changed`, `reserved_difference_before`, `reserved_difference_after`, `warehouse_stock_changed`, and `stock_movement_created`.

## Drift prevention and retry safety

`ReservationSideEffects` accumulates affected product IDs only inside a reservation transaction attempt. At the successful outer boundary, before commit, it invokes canonical projection rebuild once for the sorted affected set. Nested `run()` and nested reservation transactions share the current attempt context.

Each Laravel transaction retry receives a fresh accumulator. Exceptions discard attempt state in `finally`; no IDs leak into a retry or later request. Calls outside a database transaction do not retain state. External integrations remain deferred until commit.

Reservation mutation paths stop incrementing or decrementing reserved projections directly and instead mark affected products after the canonical reservation row mutation. Physical stock changes remain exactly where legitimate creation/release currently performs them.

## Concurrency

All rebuild operations use deterministic ascending lock order: product IDs, variant IDs, then reservation IDs for the affected products. Expected quantities are queried only after locks are acquired. Concurrent rebuilds or reservation mutations for the same product serialize and cannot overwrite projections from different snapshots.

Warehouse availability decisions lock canonical warehouse rows on mutation paths. Read-only availability queries do not lock or create rows. Negative warehouse quantity remains rejected by `WarehouseStockService::change()`.

## Compatibility and exclusions

- Projection columns remain available to display/export code.
- Display-only reads may retain projection columns when freshness is guaranteed by canonical synchronization.
- Explicit disaster recovery and administrative reconciliation remain separate.
- No price, commission, unrelated UI, production data, deployment, or Phase 3 work is included.

## Verification

Tests cover side-effect-free stock reads; stock projection synchronization; canonical active reservation inclusion/exclusion; dry-run zero writes; apply isolation, locking, idempotency, and summaries; retry/nesting state isolation; reservation create/release/consume physical semantics; Phase 1 cleanup invariants; relevant inventory/preinvoice/invoice flows; and the full suite.
