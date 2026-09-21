# Inventory Reservation Phase 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make stale-reservation audit, cleanup, and management semantics use canonical row classification while preserving physical-stock lifecycle rules and exposing historical ambiguity as a read-only review queue.

**Architecture:** `ReservationClassificationService` remains the sole row-level authority. The stock-integrity audit will eagerly load classification relationships, classify each reservation at one evaluation time, emit R03/R04 only for `temporary_stale_releasable`, and emit read-only R06 rows for `historical_ambiguous`. Every automated or manual stale release path will lock, reload relationships, and classify immediately before physical stock is returned; dashboard actionable counts will be derived from the same canonical state.

**Tech Stack:** Laravel, Eloquent, Artisan commands, Pest/PHPUnit, SQLite/MySQL-compatible queries.

**Spec:** `C:/Users/1/.codex/attachments/d78e3235-6b85-44e8-bbd2-18e87afe99a1/pasted-text.txt`

## Global Constraints

- Do not redo Phase 1 or Phase 2, modify reservation projections, or address P01/P02/P03.
- Never release physical stock based only on age, expiry, heartbeat, a report row, or a stale candidate ID.
- Real stale release returns central stock exactly once; consumption, legacy cleanup, and historical ambiguity never return stock.
- Historical ambiguous rows are visibility-only and must remain immutable through stale cleanup.
- Work only on `fix/inventory-reservation-phase-3`; do not touch `.claude/`, commit, push, deploy, or run production mutation.

## Review Focus

- A row can change classification between candidate selection and mutation; tests must prove locked reclassification refuses it.
- An old expired temporary row can match the stale SQL prefilter while canonically historical; tests must keep it out of R03/R04 and physical release.
- Active-draft ownership outranks expiry; tests must keep an expired active draft out of actionable audit and cleanup.
- Projection cache corruption must not authorize or multiply a physical stock return; tests must cover zero and inflated cache values.
- Audit filters and CSV/JSON output must retain enough relationship context to review missing, invoice-linked, and active-draft cases without writes.

---

### Task 1: Canonical R03/R04 and historical R06 reporting

**Files:**
- Modify: `tests/Feature/StockReservationIntegrityAuditCommandTest.php`
- Modify: `app/Console/Commands/AuditStockReservationIntegrity.php`

**Interfaces:**
- Consumes: `ReservationClassificationService::classify(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at): array`
- Produces: canonical `stale-temporary-reservations` R03/R04 rows and `historical-ambiguous-reservations` R06 rows with review context; summary keys for count and quantity.

- [ ] **Step 1: Write failing audit tests**

Add fixtures covering fresh temporary, active-draft-owned, recent stale online, recent stale in-person, old historical temporary, official historical stock-released, missing-order historical, and terminal/protected states. Assert only the two recent canonical stale rows emit R03/R04; historical rows emit R06 with token, user, product, variant, quantity, scope, order/status, invoice/draft flags, timestamps, age, and canonical reason.

- [ ] **Step 2: Run the focused test to verify RED**

Run: `php artisan test tests/Feature/StockReservationIntegrityAuditCommandTest.php --filter=canonical`

Expected: FAIL because the age query still emits historical rows as R03/R04 and no R06 report/context exists.

- [ ] **Step 3: Implement canonical audit partitioning**

Replace the independent stale decision with an eager-loaded reservation query and one fixed evaluation timestamp. Classify every candidate row and partition strictly by `STATE_TEMPORARY_STALE_RELEASABLE` and `STATE_HISTORICAL_AMBIGUOUS`. Add R06 as manual review with explicit no-release/no-stock-return guidance, extend report columns with the required context, and add historical count/quantity summary fields.

- [ ] **Step 4: Run the audit tests to verify GREEN**

Run: `php artisan test tests/Feature/StockReservationIntegrityAuditCommandTest.php`

Expected: PASS with no database writes.

### Task 2: Harden direct expiry release services

**Files:**
- Modify: `tests/Feature/WarehouseReservationCleanupSafetyTest.php`
- Modify: `app/Services/PreinvoiceDraftReservationService.php`
- Modify: `app/Services/PreinvoiceReservationService.php`

**Interfaces:**
- Consumes: canonical classification after `lockForUpdate()` and eager loading `order.invoice` plus `activeDrafts`.
- Produces: expiry cleanup that releases only `STATE_TEMPORARY_STALE_RELEASABLE` and otherwise performs no mutation.

- [ ] **Step 1: Write failing expiry-path safety tests**

Add tests for both expiry services proving stale recent rows return stock once, repeated calls are idempotent, and active-valid, invoice-linked, consumed, released, legacy-safe, and historical-ambiguous rows are refused. Assert warehouse quantity and stock-movement count remain unchanged for refused rows.

- [ ] **Step 2: Run the focused tests to verify RED**

Run: `php artisan test tests/Feature/WarehouseReservationCleanupSafetyTest.php --filter=expired`

Expected: FAIL where expiry-only paths release rows without canonical reclassification.

- [ ] **Step 3: Add locked canonical reclassification**

Inject `ReservationClassificationService` into `PreinvoiceReservationService`. In both expiry methods, acquire row locks first, eager load required relationships, classify at mutation time, and continue only for `STATE_TEMPORARY_STALE_RELEASABLE`. Preserve existing physical release and audit behavior for accepted rows.

- [ ] **Step 4: Run focused cleanup tests to verify GREEN**

Run: `php artisan test tests/Feature/WarehouseReservationCleanupSafetyTest.php`

Expected: PASS.

### Task 3: Prove command, schedule, manual release, and cache-corruption safety

**Files:**
- Modify: `tests/Feature/WarehouseReservationCleanupSafetyTest.php`
- Modify if evidence requires: `app/Services/PreinvoiceDraftReservationService.php`
- Modify if evidence requires: `app/Services/InventoryReservationReleaseService.php`

**Interfaces:**
- Consumes: `cleanupStaleTemporaryReservations()` and `releaseDraftReservation()` locked classification gates.
- Produces: regression evidence that `reservations:cleanup`, its deprecated delegate, its scheduled invocation, and warehouse manual release share the canonical mutation boundary.

- [ ] **Step 1: Add command and mutation-boundary regressions**

Test genuine stale release and exact-once stock return through `reservations:cleanup`; deprecated command delegation; historical/active/official/invoice/consumed/released/legacy refusal; and stock return independence from corrupted `products.reserved` / `product_variants.reserved` projections.

- [ ] **Step 2: Run tests and verify behavior**

Run: `php artisan test tests/Feature/WarehouseReservationCleanupSafetyTest.php tests/Feature/WarehouseReservationLifecycleTest.php tests/Feature/WarehouseReservationFullLifecycleTest.php`

Expected: PASS. If a failing test identifies a missing lock/classification gate, make the smallest service change and rerun until green.

### Task 4: Canonical dashboard actionable counts

**Files:**
- Modify: `tests/Feature/ReservationDashboardTest.php`
- Modify: `app/Services/ReservationQueryService.php`
- Modify: `resources/views/warehouse-reservations/partials/dashboard-cards.blade.php`

**Interfaces:**
- Consumes: canonical classifications for visible reservations.
- Produces: `releasable` containing only `temporary_stale_releasable` and a separate `historical_ambiguous` review counter; no physical or projection writes.

- [ ] **Step 1: Write failing dashboard tests**

Assert an old historical temporary row is excluded from `releasable`, included in `historical_ambiguous`, and does not change warehouse stock or stock movements. Assert a recent genuinely stale row remains releasable.

- [ ] **Step 2: Run dashboard tests to verify RED**

Run: `php artisan test tests/Feature/ReservationDashboardTest.php`

Expected: FAIL because `abandonedTemporary()` currently treats historical rows as releasable and no separate historical counter exists.

- [ ] **Step 3: Implement classification-driven counters and labels**

Eager load classification relationships from the management-visible set, classify at one evaluation time, aggregate actionable stale and historical ambiguity independently, and update the existing cards without redesigning unrelated UI.

- [ ] **Step 4: Run dashboard tests to verify GREEN**

Run: `php artisan test tests/Feature/ReservationDashboardTest.php tests/Feature/WarehouseReservationManagementUiTest.php`

Expected: PASS.

### Task 5: Phase regression and static verification

**Files:**
- Verify all modified PHP files.

**Interfaces:**
- Consumes: Phase 1/2 test suites and repository test configuration.
- Produces: fresh evidence for the final review report.

- [ ] **Step 1: Run focused Phase 3 tests**

Run the audit, cleanup safety, dashboard, management UI, lifecycle, and full-lifecycle test files.

- [ ] **Step 2: Run Phase 1 reservation tests**

Run classification, legacy cleanup, reservation cleanup audit, production hardening, and Phase 1 lifecycle test files.

- [ ] **Step 3: Run Phase 2 tests**

Run stock-read, projection, side-effect, writer-boundary, and audit tests identified by `rg --files tests | rg 'Reservation|Inventory|Stock'` and the Phase 2 plan.

- [ ] **Step 4: Run the full suite**

Run: `php artisan test`

- [ ] **Step 5: Run syntax and whitespace checks**

Run `php -l` for every changed PHP file and `git diff --check`.

- [ ] **Step 6: Capture final repository evidence**

Run `git diff --stat`, `git status --short --branch`, and inspect the complete diff. Report all failures without omission and provide only read-only future production commands.
