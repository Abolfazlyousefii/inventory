# Inventory and Reservation Authority Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make central warehouse quantity and canonical active reservations the sole business truths, safely rebuild their projections, and prevent future reservation-cache drift.

**Architecture:** `WarehouseStockService` owns normal physical availability reads and mutations. `ReservationQueryService` defines active reservations, while a new `ReservationProjectionService` performs deterministic lock-then-recalculate projection rebuilds; `ReservationSideEffects` batches affected products per retry-safe transaction attempt.

**Tech Stack:** PHP 8.3, Laravel, Eloquent/query builder, Artisan, Pest/PHPUnit, database transactions and row locks.

**Spec:** `docs/superpowers/specs/2026-09-21-inventory-reservation-phase-2-design.md`

## Global Constraints

- Do not deploy, run production repair, release stale/invalid reservations, mutate historical ambiguity, change prices/commissions/unrelated UI, or start Phase 3.
- Preserve physical lifecycle semantics: creation subtracts once, legitimate release returns once, consumption and legacy cleanup return nothing.
- Canonical reads are side-effect free; missing warehouse rows read as zero.
- Projection expected values are calculated only after deterministic locks are held.
- Retry and nested transaction state must never leak across attempts.
- Administrative recovery/import/stock-count writers remain explicit and separate.

## Review Focus

- Missing central warehouse row during validation returns zero without an insert; mutation may still create it explicitly.
- A deadlocked/failed first transaction attempt does not carry affected product IDs into the retry.
- Two rebuilds touching overlapping products use identical lock ordering and final canonical values.
- A reservation conversion marks lifecycle consumed without returning warehouse quantity or retaining reserved projection.
- Products with multiple variants aggregate all canonical variant totals, including variants whose expected total is zero.

---

### Task 1: Side-effect-free canonical stock reads and sanctioned stock writers

**Files:**
- Modify: `app/Services/WarehouseStockService.php`
- Modify: `app/Http/Controllers/PurchaseController.php`
- Modify selectively: normal workflow callers found by the audit
- Test: `tests/Feature/CanonicalInventoryAuthorityTest.php`

**Interfaces:**
- Produces: `WarehouseStockService::available(int $warehouseId, int $productId, ?int $variantId = null): int` as a read-only method.
- Preserves: `change()` and `set()` as explicit mutation paths that may create and lock rows.

- [ ] Write tests proving a missing-row `available()` returns zero without writes, central `change()` synchronizes variant and product stock projections, product stock is the central variant aggregate, and negative changes fail atomically.
- [ ] Run `php artisan test tests/Feature/CanonicalInventoryAuthorityTest.php` and verify failures identify read-side insertion and any duplicate projection writer.
- [ ] Replace `ensureStockExists()` in `available()` with a non-mutating lookup and zero fallback.
- [ ] Remove purchase-flow writes to `ProductVariant::stock`; validate against canonical warehouse availability and let `WarehouseStockService::change()` own projection synchronization.
- [ ] Review each normal direct warehouse/projection writer from the audit; reroute only semantically normal mutations while documenting guarded administrative exceptions in the test/audit map.
- [ ] Run the focused test and relevant purchase/warehouse tests; expect all pass.

### Task 2: Deterministic canonical reservation projection service

**Files:**
- Create: `app/Services/ReservationProjectionService.php`
- Modify: `app/Services/ReservationQueryService.php`
- Test: `tests/Feature/ReservationProjectionServiceTest.php`

**Interfaces:**
- Produces: `inspect(array $productIds = [], ?CarbonInterface $at = null): array` for read-only drift reporting.
- Produces: `rebuild(array $productIds, ?CarbonInterface $at = null): array` for lock-then-recalculate projection writes inside a transaction.
- Produces summary and variant/product change rows with literal before/expected/difference values.

- [ ] Write tests with active temporary, active draft-owned, active official, consumed, released, invoice-linked, historical ambiguous, and zero-expected variants; prove only `ReservationQueryService::activeQuery()` contributes.
- [ ] Write an interleaving/concurrency regression proving final expected values are computed after locks and repeated rebuild is idempotent.
- [ ] Run the new test and verify it fails because the service does not exist.
- [ ] Implement deterministic sorted locking for products, variants, and affected reservation rows; after locks, call canonical quantity aggregation and update variant then product projections.
- [ ] Ensure inspect performs no writes and rebuild snapshots warehouse stock, stock projections, movements, and lifecycle rows only for test verification—not as write inputs.
- [ ] Make `ReservationQueryService::rebuildForProducts()` delegate to the new service or reduce it to one sanctioned primitive without circular dependency.
- [ ] Run projection, query-unification, classification, and Phase 1 cleanup tests; expect all pass.

### Task 3: Safe explicit reservation-cache repair command

**Files:**
- Modify: `app/Console/Commands/RepairReservedCache.php`
- Test: `tests/Feature/RepairReservedCacheCommandTest.php`

**Interfaces:**
- Consumes: `ReservationProjectionService::inspect()` and `rebuild()`.
- Produces command contract: `inventory:repair-reserved-cache --dry-run` or `inventory:repair-reserved-cache --apply --confirm`.

- [ ] Write command tests proving mode validation, mandatory confirmation, affected row details, required summary fields, dry-run zero writes, apply no warehouse/stock/movement/lifecycle changes, and second apply zero changes.
- [ ] Run the command test and verify failures for missing confirmation and noncanonical/exclusion-based calculations.
- [ ] Replace the command’s independent `protectedDemand()`, `activeTemporary()`, exclusion, locking, and apply logic with the projection service.
- [ ] Keep report output under the explicit `--output` directory and retain the dry-run SQL write guard.
- [ ] Ensure apply recalculates under lock rather than writing dry-run values.
- [ ] Run command, integrity-audit, and projection tests; expect all pass.

### Task 4: Retry-safe, nested projection synchronization boundary

**Files:**
- Modify: `app/Support/ReservationSideEffects.php`
- Modify: `app/Services/PreinvoiceDraftReservationService.php`
- Modify: `app/Services/InventoryReservationReleaseService.php`
- Modify: `app/Services/PreinvoiceReservationService.php`
- Modify: `app/Http/Controllers/PreinvoiceController.php`
- Modify selectively: other canonical lifecycle boundaries identified in the audit
- Test: `tests/Feature/ReservationProjectionLifecycleTest.php`
- Test: `tests/Unit/ReservationSideEffectsTest.php`

**Interfaces:**
- Produces: `ReservationSideEffects::touchProduct(int $productId): void` usable only within the active attempt context.
- Preserves: `run()`, `transaction()`, and `dispatch()` public behavior.

- [ ] Write unit tests proving nested calls coalesce sorted IDs, exceptions reset state, separate outer transactions do not share state, and simulated first-attempt failure/retry rebuilds only the successful attempt’s IDs.
- [ ] Write lifecycle tests proving create updates reserved projection and subtracts physical stock once; release updates projection and returns physical stock once; repeat release is idempotent; consumption removes projection without returning stock; legacy cleanup remains stock-neutral.
- [ ] Run tests and verify failures show absent accumulation/reconciliation.
- [ ] Implement attempt-local accumulator context around each Laravel transaction callback, with `finally` restoration/reset and outermost successful reconciliation before commit.
- [ ] Replace direct reserved increments/decrements in normal lifecycle paths with `touchProduct()` after canonical reservation-row mutation; retain existing physical `WarehouseStockService::change()` calls only where creation/release semantics require them.
- [ ] Confirm no observer or after-commit callback performs projection writes.
- [ ] Run lifecycle, side-effects, preinvoice, invoice conversion, release, cleanup, and concurrency tests; expect all pass.

### Task 5: Canonical reserved business reads and integrity audit consistency

**Files:**
- Modify: `app/Services/StockCountDocumentService.php`
- Modify selectively: business validation/read callers identified in the audit
- Modify if required: `app/Console/Commands/AuditStockReservationIntegrity.php`
- Test: `tests/Feature/CanonicalReservedReadTest.php`
- Test: `tests/Feature/StockReservationIntegrityAuditCommandTest.php`

**Interfaces:**
- Consumes: `ReservationQueryService::quantitiesByVariant()`.
- Preserves separate health-monitoring R03/R04/R05 definitions.

- [ ] Write tests proving stock-count and availability decisions ignore corrupted reserved projections and use canonical active rows.
- [ ] Prove R01 equals canonical query quantities for inclusion/exclusion fixtures while health anomaly categories remain independent.
- [ ] Run tests and verify cache-trusting paths fail.
- [ ] Inject/use `ReservationQueryService` in business readers and remove alternate active-reservation WHERE clauses.
- [ ] Keep display-only projection reads where no business decision depends on them.
- [ ] Run focused reserved-read and audit tests; expect all pass.

### Task 6: Complete writer audit, compatibility verification, and handoff

**Files:**
- Modify only files required by uncovered normal writers
- Test: existing Phase 1 and Phase 2 suites

**Interfaces:**
- Consumes all prior canonical services.
- Produces the final writer map and production verification commands.

- [ ] Re-run searches for direct `stock`/`reserved` mutations and classify every remaining writer as canonical projection synchronization, explicit guarded administration, initialization-to-zero, or a defect to reroute.
- [ ] Run focused Phase 2 tests.
- [ ] Run all Phase 1 reservation tests.
- [ ] Run relevant inventory, purchase, preinvoice, invoice, return, transfer, stock-count, and audit tests.
- [ ] Run `php artisan test` and require zero failures.
- [ ] Run PHP syntax checks for every changed PHP file and `git diff --check`.
- [ ] Record exact future production workflow: integrity audit, repair dry-run to a reviewed output directory, explicit apply with confirmation, and integrity audit again. Do not execute it.
- [ ] Report diff stat/status and stop for review without deployment or Phase 3 work.
