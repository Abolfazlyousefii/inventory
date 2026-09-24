# Inventory Reservation Phase 4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the existing physical-stock lifecycle contract, then make reservation management canonically classified and add a stock-neutral archive for historical ambiguous reservations.

**Architecture:** Task 0 adds endpoint-level contract tests and is a hard stop gate. After it passes, a presentation service maps only canonical classification results to management buckets and display metadata; a separate archive service performs locked, reclassified lifecycle closure without invoking any stock or projection mutation path.

**Tech Stack:** PHP 8+, Laravel, Eloquent transactions/row locks, Artisan commands, Pest/PHPUnit, Blade.

**Spec:** `docs/superpowers/specs/2026-09-22-inventory-reservation-phase-4-design.md`

## Global Constraints

- `ReservationClassificationService::classify()` is the final business authority.
- Task 0 must pass before any Phase 4 UI/archive product code is changed.
- If Task 0 fails, stop and report the exact assertion and affected lifecycle code path.
- Archive only rows that reclassify under lock as `historical_ambiguous`.
- Archive must not call `WarehouseStockService::change()`, modify physical stock, create stock movements, mutate/rebuild projections, or hard-delete rows.
- Use lifecycle reason `historical_reconciliation_stock_neutral` and UI label `پاکسازی تاریخی — بدون تغییر موجودی`.
- Do not mutate production, deploy, push, or commit. Replace normal commit steps with review checkpoints.
- Do not touch `.claude/` or unrelated working-tree changes.

## Review Focus

- An explicit ID that was historical when listed but becomes active before locking must be skipped; Task 3 tests reclassification under lock/service execution.
- `--ids` containing duplicates, missing IDs, or mixed eligible/ineligible states must produce stable counts and touch only eligible rows; Task 4 tests mixed selection.
- `--apply` without `--confirm`, contradictory selectors, and missing selectors must fail without writes; Task 4 tests command validation.
- Default management pagination must not leak review/history states or lose canonical active rows; Task 2 tests every state against buckets.
- Archiving multiple rows for the same and different variants must leave central totals and each affected stock row identical; Task 3 snapshots both aggregate and per-row quantities.

---

### Task 0: Reservation Physical-Stock Lifecycle Contract Gate

**Files:**
- Create: `tests/Feature/ReservationLifecycleContractPhaseFourTest.php`
- Read/trace on failure only: `app/Services/PreinvoiceDraftReservationService.php`
- Read/trace on failure only: `app/Services/PreinvoiceReservationService.php`
- Read/trace on failure only: preinvoice submission/finalization controllers and services reached by named routes

**Interfaces:**
- Consumes: real routes `preinvoice.api.reservations.sync`, `preinvoice.draft.save`, `preinvoice.draft.finalize`, `preinvoice.reservations.release-token`, and command `reservations:cleanup`.
- Consumes: `ReservationQueryService::quantitiesByVariant(?int $productId = null, array $variantIds = [], ?bool $official = null, ?CarbonInterface $at = null): Collection` as canonical reserved authority.
- Produces: a green/red lifecycle gate; no product implementation.

- [ ] **Step 1: Add isolated fixtures and authority helpers**

Create Pest helpers in the new test file that mirror the real lifecycle fixtures already proven in `WarehouseReservationFullLifecycleTest.php`, but use 100 physical units and read authorities directly:

```php
function phaseFourPhysicalQuantity(int $productId, int $variantId): int
{
    return (int) WarehouseStock::query()
        ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
        ->where('product_id', $productId)
        ->where('product_variant_id', $variantId)
        ->value('quantity');
}

function phaseFourCanonicalReserved(int $productId, int $variantId): int
{
    return (int) app(ReservationQueryService::class)
        ->quantitiesByVariant($productId, [$variantId])
        ->get($variantId, 0);
}
```

Build seller/finance permissions and category/product/variant/central `WarehouseStock` fixtures without model side effects, following the existing full-lifecycle test helpers.

- [ ] **Step 2: Write the primary 100 → 90 lifecycle test**

Use the real sync endpoint to reserve 10; submit the real final preinvoice payload; finalize through finance. Assert after each boundary:

```php
expect(phaseFourPhysicalQuantity($product->id, $variant->id))->toBe(90)
    ->and(phaseFourCanonicalReserved($product->id, $variant->id))->toBe(10);
// After finance conversion:
expect(phaseFourPhysicalQuantity($product->id, $variant->id))->toBe(90)
    ->and(phaseFourCanonicalReserved($product->id, $variant->id))->toBe(0)
    ->and($reservation->fresh()->release_reason)->toBe('consumed');
```

Also assert the official transition does not add a second decrement, invoice conversion does not return stock, stock-movement count does not grow at either transition, and no open reservation remains for the order.

- [ ] **Step 3: Write delta-adjustment tests**

Sync 10, then sync 15 with the same token and assert physical/reserved `85/15`. In a separate test sync 10, then 6 and assert `94/6`. Snapshot `warehouse_stocks.quantity` directly before each second request to prove deltas of `-5` and `+4`.

- [ ] **Step 4: Write exactly-once release and cleanup tests**

Exercise explicit token cancellation and a genuinely stale temporary cleanup. Assert `90/10 → 100/0`, then retry the same release and run cleanup again; assert physical quantity remains 100, canonical reserved remains zero, and only the first successful release can create its expected stock-side effect.

- [ ] **Step 5: Write retry/idempotency and corrupt-projection tests**

Repeat final submission with the same token/payload and assert one official order/reservation, physical 90, canonical reserved 10. Separately corrupt `products.reserved` and `product_variants.reserved` before a real quantity adjustment/release and assert physical decisions follow the locked reservation delta, not either cache value.

- [ ] **Step 6: Run the hard gate**

Run:

```powershell
php artisan test tests/Feature/ReservationLifecycleContractPhaseFourTest.php --stop-on-failure
```

Expected: every assertion passes. If any assertion fails, stop all work, preserve the failing test, identify the route/service/method that produced the wrong physical or canonical value, and report it for review.

- [ ] **Step 7: Review checkpoint (no commit)**

Run `git diff -- tests/Feature/ReservationLifecycleContractPhaseFourTest.php` and confirm the file contains tests only.

### Task 1: Canonical Presentation Mapper

**Files:**
- Create: `app/Services/ReservationManagementPresentationService.php`
- Create or modify: `tests/Feature/ReservationManagementPresentationTest.php`
- Modify: `app/Services/ReservationClassificationService.php` only if shared label constants are needed

**Interfaces:**
- Consumes: `ReservationClassificationService::classify(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array`.
- Produces: `present(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array{classification:array,bucket:string,label:string,badge:string,warning:?string,priority:int,can_release:bool,can_archive:bool}`.
- Produces bucket constants `BUCKET_CURRENT`, `BUCKET_ACTIONABLE`, `BUCKET_REVIEW`, `BUCKET_HISTORY`.

- [ ] **Step 1: Write the failing state-matrix test**

Create fixtures for all ten canonical states and assert the exact mapping. Pin these critical cases:

```php
expect($presenter->present($activeValid)['bucket'])->toBe($presenter::BUCKET_CURRENT)
    ->and($presenter->present($activeValid)['can_release'])->toBeFalse()
    ->and($presenter->present($historical)['bucket'])->toBe($presenter::BUCKET_REVIEW)
    ->and($presenter->present($historical)['can_archive'])->toBeTrue()
    ->and($presenter->present($stale)['can_release'])->toBeTrue();
```

Assert only `temporary_stale_releasable` has `can_release=true`; only `historical_ambiguous` has `can_archive=true`; active valid and official active labels contain active/protected wording and no cleanup/release wording.

- [ ] **Step 2: Run the focused test and verify the expected failure**

Run `php artisan test tests/Feature/ReservationManagementPresentationTest.php --stop-on-failure`.
Expected: failure because the presenter class does not exist.

- [ ] **Step 3: Implement the mapper as a total state match**

Implement `present()` by calling the classifier once and matching its `state`. Do not call any legacy model presentation helper. Unknown future states must map to Review with no action rather than becoming releasable.

- [ ] **Step 4: Run the focused test**

Run `php artisan test tests/Feature/ReservationManagementPresentationTest.php --stop-on-failure`.
Expected: pass.

- [ ] **Step 5: Review checkpoint (no commit)**

Confirm `rg -n "businessStatus|isAbandoned|canBeManuallyReleased|managementWarning|managementPriority|needsManagementReview" app/Services/ReservationManagementPresentationService.php` returns no matches.

### Task 2: Canonical Management Queries, Dashboard, and Views

**Files:**
- Modify: `app/Services/ReservationQueryService.php`
- Modify: `app/Http/Controllers/WarehouseReservationController.php`
- Modify: `resources/views/warehouse-reservations/partials/dashboard-cards.blade.php`
- Modify: `resources/views/warehouse-reservations/partials/reservation-table.blade.php`
- Modify: `resources/views/warehouse-reservations/index.blade.php`
- Modify: `resources/views/warehouse-reservations/show.blade.php`
- Modify: `tests/Feature/WarehouseReservationManagementUiTest.php`
- Modify: `tests/Feature/ReservationDashboardTest.php`
- Modify: `tests/Feature/ReservationFilteringTest.php`

**Interfaces:**
- Consumes: `ReservationManagementPresentationService::present()` and bucket constants.
- Produces: canonical default/current, actionable, review, and history query populations; canonical JSON/display metadata.

- [ ] **Step 1: Write failing management population tests**

Create one row per canonical state. Assert an unfiltered request contains only current states; `quick=actionable` contains only stale releasable; `quick=review` contains historical ambiguous/invalid official/legacy safe; history contains released/consumed/invoice-linked as supported by the existing schema. Assert pagination totals equal the displayed canonical population.

- [ ] **Step 2: Write failing UI action/copy tests**

Assert active valid never renders `قابل پاکسازی` or `قابل آزادسازی`, historical ambiguous renders review/archive wording without a release form, official active renders active wording, and only stale releasable renders the normal release action.

- [ ] **Step 3: Write failing canonical dashboard test**

Build contradictory legacy-looking fixtures whose canonical states differ and assert actionable count/quantity equals only `temporary_stale_releasable`; review count uses canonical Review states.

- [ ] **Step 4: Run the focused tests and verify failure**

Run:

```powershell
php artisan test tests/Feature/WarehouseReservationManagementUiTest.php tests/Feature/ReservationDashboardTest.php tests/Feature/ReservationFilteringTest.php --stop-on-failure
```

Expected: failures exposing current legacy-driven labels/populations.

- [ ] **Step 5: Centralize canonical bucket queries**

Add query methods in `ReservationQueryService` for the four buckets. SQL may prefilter, but returned/displayed rows must be classified and presented canonically. Keep pagination totals correct and eager-load `order.invoice` plus `activeDrafts`.

- [ ] **Step 6: Refactor controller response and dashboard data**

Inject the presenter. Replace `business_status`, `display_reason`, `releasable`, `priority`, `importance`, and `warning` derivations with canonical presentation values. Compute dashboard actionable/review counters from canonical state membership.

- [ ] **Step 7: Refactor Blade rendering**

Pass precomputed presentation data to views or invoke only the presenter. Remove legacy-helper calls from the reservation-management templates. Ensure the default tab is Current and history remains distinct.

- [ ] **Step 8: Run focused UI/query/dashboard tests**

Run the Task 2 command again. Expected: pass.

- [ ] **Step 9: Prove legacy helpers no longer drive the page**

Run:

```powershell
rg -n "businessStatus\(|isAbandoned\(|canBeManuallyReleased\(|managementWarning\(|managementPriority\(|needsManagementReview\(" app/Http/Controllers/WarehouseReservationController.php resources/views/warehouse-reservations
```

Expected: no management-page matches.

### Task 3: Locked Stock-Neutral Historical Archive Service

**Files:**
- Create: `app/Services/HistoricalReservationArchiveService.php`
- Create: `tests/Feature/HistoricalReservationArchiveServiceTest.php`
- Modify: `app/Models/PreinvoiceDraftReservation.php` for the archive reason label only

**Interfaces:**
- Consumes: `ReservationClassificationService::classify()`.
- Produces: constants `RELEASE_REASON`, `RELEASE_NOTE`, `ACTIVITY_ACTION`, `ACTION_ARCHIVED`, `ACTION_SKIPPED`.
- Produces: `candidatesQuery(?CarbonInterface $at = null): Builder`, `reportRows(array $ids, CarbonInterface $at): Collection`, and `archive(array $ids, CarbonInterface $at, ?int $actorId = null): array`.

- [ ] **Step 1: Write failing eligibility and idempotency tests**

Create historical ambiguous plus active valid, temporary active, official active, invoice linked, consumed, released, and legacy safe rows. Pass every ID and assert only historical ambiguous closes with reason `historical_reconciliation_stock_neutral`; retry and assert zero additional archived rows.

- [ ] **Step 2: Write failing stock/projection invariants**

Before archival snapshot:

```php
$centralTotal = WarehouseStock::query()->where('warehouse_id', $centralId)->sum('quantity');
$affectedRows = WarehouseStock::query()->where('warehouse_id', $centralId)
    ->whereIn('product_variant_id', $variantIds)->orderBy('id')->get(['id', 'quantity'])->toArray();
$movements = StockMovement::query()->count();
$projection = app(ReservationQueryService::class)->quantitiesByVariant(variantIds: $variantIds)->all();
```

After archival assert the aggregate total, each stock row, movement count, and canonical projection snapshot are identical. Assert the reservation still exists and is queryable by its archive reason.

- [ ] **Step 3: Write failing locked reclassification test**

Select a historical candidate, mutate its ownership/lifecycle into an ineligible canonical state before `archive()` acquires its lock, and assert the service reports `SKIPPED` and preserves all lifecycle fields.

- [ ] **Step 4: Run the focused service test and verify failure**

Run `php artisan test tests/Feature/HistoricalReservationArchiveServiceTest.php --stop-on-failure`.
Expected: failure because the service does not exist.

- [ ] **Step 5: Implement the minimal archive service**

For each unique integer ID, run a transaction with retry count 3, reload using `lockForUpdate()`, load `order.invoice` and `activeDrafts`, classify at the captured time, and update only the four audit lifecycle fields. Log an activity without calling release, warehouse-stock, movement, or projection services.

- [ ] **Step 6: Add the explicit history label**

Map `historical_reconciliation_stock_neutral` in `releaseReasonLabel()` to `پاکسازی تاریخی — بدون تغییر موجودی`.

- [ ] **Step 7: Run the focused service test**

Run `php artisan test tests/Feature/HistoricalReservationArchiveServiceTest.php --stop-on-failure`.
Expected: pass.

- [ ] **Step 8: Static side-effect guard**

Run:

```powershell
rg -n "WarehouseStockService|StockMovement|rebuildForProducts|ReservationProjectionService|delete\(" app/Services/HistoricalReservationArchiveService.php
```

Expected: no matches.

### Task 4: Historical Archive Artisan Command

**Files:**
- Create: `app/Console/Commands/ArchiveHistoricalReservations.php`
- Create: `tests/Feature/ArchiveHistoricalReservationsCommandTest.php`

**Interfaces:**
- Consumes: `HistoricalReservationArchiveService::reportRows()` and `archive()`.
- Produces command `inventory:archive-historical-reservations {--dry-run} {--apply} {--confirm} {--ids=} {--all-historical}`.

- [ ] **Step 1: Write failing dry-run and validation tests**

Assert default and explicit `--dry-run` write nothing; no selector fails; both selectors fail; `--apply` without `--confirm` fails; `--confirm` without `--apply` fails. Snapshot reservation rows, stock rows, movements, and projections around each invalid/dry-run invocation.

- [ ] **Step 2: Write failing mixed-ID and all-historical apply tests**

For `--ids`, include duplicates, a missing ID, one historical row, and every protected state; assert only the historical row archives. For `--all-historical`, assert every and only canonical historical ambiguous row archives. Assert clear selected/eligible/archived/skipped/quantity output.

- [ ] **Step 3: Run the focused command tests and verify failure**

Run `php artisan test tests/Feature/ArchiveHistoricalReservationsCommandTest.php --stop-on-failure`.
Expected: command-not-defined failure.

- [ ] **Step 4: Implement validation and dry-run**

Normalize comma-separated IDs to unique positive integers. Require exactly one selector. Treat absence of `--apply` as dry-run; reject unsafe flag combinations. Load/report candidates without writes and print `NO DATA CHANGED`.

- [ ] **Step 5: Implement confirmed apply**

Require `--apply --confirm`, delegate to the archive service, and print stock-neutral summary output. Do not prompt interactively, so automation behavior is deterministic.

- [ ] **Step 6: Run focused command tests**

Run `php artisan test tests/Feature/ArchiveHistoricalReservationsCommandTest.php --stop-on-failure`.
Expected: pass.

### Task 5: Archive History Presentation and Audit Detail

**Files:**
- Modify: `app/Http/Controllers/WarehouseReservationController.php`
- Modify: `resources/views/warehouse-reservations/index.blade.php`
- Modify: `resources/views/warehouse-reservations/show.blade.php`
- Modify: `tests/Feature/WarehouseReservationManagementUiTest.php`
- Modify: `tests/Feature/ReservationDetailTest.php`

**Interfaces:**
- Consumes: archive lifecycle reason and activity action from Task 3.
- Produces: explicit stock-neutral history label and queryable detail/audit record.

- [ ] **Step 1: Write failing history/detail tests**

Archive a historical row through the service, request history and detail pages, and assert the exact Persian stock-neutral label appears. Assert the row is absent from default/current and present in history. Assert normal release rows do not receive the archive label.

- [ ] **Step 2: Run focused tests and verify failure**

Run `php artisan test tests/Feature/WarehouseReservationManagementUiTest.php tests/Feature/ReservationDetailTest.php --stop-on-failure`.

- [ ] **Step 3: Include archive events and copy in history/detail**

Extend the existing activity action list to include the archive action. Render the reason label and note, and adjust history explanatory copy to distinguish stock-neutral archive from normal stock return.

- [ ] **Step 4: Run focused tests**

Run the Task 5 command again. Expected: pass.

### Task 6: Regression and Release-Safety Validation

**Files:**
- Modify only if a test exposes a Phase 4 regression; otherwise none
- Review: all changed files

**Interfaces:**
- Consumes: all prior tasks.
- Produces: verification evidence and operator-safe production command checklist.

- [ ] **Step 1: Re-run Task 0 separately**

Run `php artisan test tests/Feature/ReservationLifecycleContractPhaseFourTest.php --stop-on-failure` and retain its output separately for the final report.

- [ ] **Step 2: Run Phase 4 focused tests**

Run:

```powershell
php artisan test tests/Feature/ReservationManagementPresentationTest.php tests/Feature/HistoricalReservationArchiveServiceTest.php tests/Feature/ArchiveHistoricalReservationsCommandTest.php tests/Feature/WarehouseReservationManagementUiTest.php tests/Feature/ReservationDashboardTest.php tests/Feature/ReservationFilteringTest.php tests/Feature/ReservationDetailTest.php
```

- [ ] **Step 3: Run Phase 1-3 reservation regressions**

Run every reservation-focused suite already present under `tests/Feature`, including classification, cleanup safety, production hardening, query unification, projection, legacy cleanup, lifecycle, business-rule, dashboard, filtering, bulk management, health, orphan, priority, and temporary-orphan tests.

- [ ] **Step 4: Run the full suite**

Run `php artisan test`. Expected: zero failures.

- [ ] **Step 5: Run syntax and build checks**

Run `php -l` over every changed PHP file. If JS/CSS build inputs changed, run `npm.cmd run build`; otherwise record that no frontend compilation input changed.

- [ ] **Step 6: Run whitespace/diff safety checks**

Run `git diff --check`, `git status --short --branch`, and review `git diff --stat` plus the full diff. Confirm `.claude/` is untouched and there are no commits.

- [ ] **Step 7: Prepare production verification commands without executing them**

Return commands for: canonical before snapshot and historical quantity; archive dry-run; central total plus per-product/variant checksums before/after; deliberately confirmed apply; post-classification audit; reserved projection dry-run; and stock-movement count/range verification.

- [ ] **Step 8: Stop for review**

Report Task 0 evidence in a distinct section before the Phase 4 implementation summary. Do not commit, push, deploy, or execute any production mutation.
