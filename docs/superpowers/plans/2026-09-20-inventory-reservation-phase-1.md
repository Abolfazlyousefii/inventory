# Inventory Reservation Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize reservation classification and make normal release, legacy cleanup, audit tooling, and management actions production-safe.

**Architecture:** One classifier returns structured state and action metadata. Query scopes remain conservative prefilters, while all mutations lock, reload, and classify. Normal release and legacy cleanup remain physically distinct.

**Tech Stack:** PHP 8, Laravel, Eloquent, Artisan, Blade, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-20-inventory-reservation-phase-1-design.md`

## Global Constraints

- Phase 1 only; do not deploy, merge, or mutate production.
- No schema migration and no new lifecycle columns.
- Legacy cleanup, ambiguity handling, audit, and cache rebuild never modify physical stock.
- Preserve unrelated work and `.claude/`.
- Every mutation reclassifies after a database row lock.

## Review Focus

- Missing official relation must be ambiguous, not legacy-safe.
- Null `preinvoice_order_id` without positive provenance must be ambiguous.
- Rows at or beyond the legacy threshold must never automatically return stock.
- Active draft/session/heartbeat protections must survive bulk and CLI paths.
- Read-only audit must issue no write SQL, including report generation database writes.

---

### Task 1: Canonical classification safety

**Files:**
- Modify: `tests/Feature/ReservationClassificationTest.php`
- Modify: `app/Services/ReservationClassificationService.php`
- Modify: `app/Models/PreinvoiceDraftReservation.php`

**Interfaces:**
- Produces: `classify(...): array{state,reason,recommended_action,would_change_warehouse_stock,...}` and state constants.

- [ ] Add regression tests for null/missing relations, old boundary, active draft, terminal and protected states.
- [ ] Run tests and verify the dangerous cases fail for the expected classifications.
- [ ] Implement the precedence-ordered canonical classifier and conservative scopes.
- [ ] Run classification tests to green.

### Task 2: Locked cleanup and normal-release separation

**Files:**
- Modify: `tests/Feature/ReservationLegacyCleanupTest.php`
- Modify: `tests/Feature/WarehouseReservationCleanupSafetyTest.php`
- Modify: `app/Services/LegacyReservationCleanupService.php`
- Modify: `app/Services/PreinvoiceDraftReservationService.php`
- Modify: `app/Console/Commands/CleanupLegacyReservations.php`
- Modify: `app/Console/Commands/CleanupStaleReservations.php`

**Interfaces:**
- Consumes: canonical classification result.
- Produces: explicit-ID legacy cleanup summary and canonical stale-release command behavior.

- [ ] Add failing invariance, idempotency, boundary, cache-consistency, and command-contract tests.
- [ ] Verify failures.
- [ ] Restrict locked mutation paths to their canonical states and consolidate stale commands.
- [ ] Run cleanup tests to green.

### Task 3: Read-only cleanup audit

**Files:**
- Create: `app/Console/Commands/AuditReservationCleanup.php`
- Create: `tests/Feature/ReservationCleanupAuditCommandTest.php`

**Interfaces:**
- Consumes: canonical classifier.
- Produces: `inventory:audit-reservation-cleanup` CSV/JSON reports and filters.

- [ ] Add failing report-field, classification, filter, and zero-write-SQL tests.
- [ ] Verify failures.
- [ ] Implement read-only report generation and query guard.
- [ ] Run audit tests to green.

### Task 4: Canonical management actions and display

**Files:**
- Modify: `tests/Feature/WarehouseReservationManagementTest.php`
- Modify: `tests/Feature/ReservationBulkManagementTest.php`
- Modify: `app/Http/Controllers/WarehouseReservationController.php`
- Modify: `resources/views/warehouse-reservations/partials/reservation-table.blade.php`
- Modify: `resources/views/warehouse-reservations/index.blade.php`

**Interfaces:**
- Consumes: canonical classification metadata.

- [ ] Add failing tests for state labels, action visibility, and locked bulk revalidation.
- [ ] Verify failures.
- [ ] Replace independent action authorization with canonical states and update labels/details.
- [ ] Run management tests to green.

### Task 5: Projection safety and final verification

**Files:**
- Modify: `tests/Feature/ReservationProductionHardeningTest.php`
- Modify: `app/Services/ReservationQueryService.php` only if tests expose a defect.

**Interfaces:**
- Consumes: canonical active query.

- [ ] Add explicit tests that rebuild changes only reserved projections and leaves stock, movements, invoice items, and preinvoice items unchanged.
- [ ] Verify RED where coverage exposes a defect, otherwise record pre-existing behavior.
- [ ] Make only necessary projection fixes.
- [ ] Run all focused reservation tests, full suite, safety searches, Git status, and diff review.
