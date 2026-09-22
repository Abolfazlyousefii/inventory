# Inventory Reservation Phase 4 Design

## Purpose

Phase 4 makes the warehouse reservation-management UI reflect the canonical reservation classifier and adds an auditable, stock-neutral terminal workflow for historical ambiguous rows. It must preserve the production-verified Phase 1-3 safety guarantees and must not mutate production data as part of development or verification.

## Non-negotiable constraints

- `ReservationClassificationService::classify()` remains the final business authority for reservation state and actionability.
- Historical archival never changes physical warehouse stock, creates stock movements, or rebuilds/mutates reserved projections.
- Reservation rows are never hard-deleted.
- Genuine active, official, invoice-linked, consumed, legacy-safe, or otherwise non-historical rows cannot be archived.
- Existing unrelated work, including `.claude/`, is not modified.
- Work remains uncommitted, unpushed, and undeployed for review.

## Task 0: reservation lifecycle contract verification

Before UI or archive implementation, add end-to-end regression coverage using `warehouse_stocks.quantity` as the physical available-stock authority and canonical reservation queries as the reserved authority.

The tests establish a central warehouse quantity of 100 and prove:

1. A genuine temporary reservation of 10 changes physical available stock to 90 and canonical reserved quantity to 10.
2. Final preinvoice submission/finance handoff converts or attaches that reservation to the official document without another stock decrement: available remains 90 and reserved remains 10.
3. Finance approval/invoice conversion consumes or closes the official reservation without changing physical stock again or returning stock: available remains 90 and reserved becomes zero.
4. A genuine pre-invoice release returns available stock from 90 to 100 exactly once and clears canonical reserved quantity.
5. A repeated release or cleanup cannot return stock a second time.
6. Increasing a reservation from 10 to 15 removes only the five-unit delta.
7. Decreasing a reservation from 10 to 6 before invoice returns exactly four units.
8. Repeated final submission, refresh, or retry cannot duplicate reservation rows, reserved quantity, or physical decrement.
9. Invoice conversion leaves no active reservation for the document.
10. A deliberately corrupted reserved projection does not influence physical-stock decisions.

These tests run before any Phase 4 product change. If any contract assertion fails against the current Phase 1-3 implementation, Phase 4 UI/archive work stops. The failure is reported for lifecycle review rather than being silently folded into the Phase 4 scope.

## Canonical management presentation

Introduce a focused presentation service that accepts a canonical classification result and returns display-only metadata: bucket, label, badge style, warning, priority, and permitted action. It contains no independent lifecycle inference and does not consult legacy presentation helpers.

Canonical buckets are:

| Bucket | States | Meaning |
| --- | --- | --- |
| Current | `active_valid`, `temporary_active`, `official_active` | Active/protected reservations |
| Actionable | `temporary_stale_releasable` | The only state eligible for normal stock-returning release |
| Review | `historical_ambiguous`, `invalid_official`, and explicitly selected `legacy_safe` | Human review, historical archive, or existing legacy workflow as appropriate |
| History | `released`, `consumed`, `invoice_linked` | Terminal or historical display only |

The default reservations table contains only the Current bucket. Explicit quick filters expose Actionable and Review. Terminal/history states do not appear in the default population. All table labels, warnings, ordering, actions, JSON fields, detail state, and dashboard actionable/review counts derive from canonical classification and its presentation mapping.

Legacy methods such as `businessStatus()`, `isAbandoned()`, `canBeManuallyReleased()`, `managementWarning()`, `managementPriority()`, and `needsManagementReview()` may remain for compatibility elsewhere, but they do not override the management page.

Query-level predicates may conservatively narrow candidate sets for performance, but every displayed row and every action decision is validated through the canonical classifier. Filter pagination must remain accurate; any SQL state predicates are centralized alongside the management query code and protected by state-by-state regression tests.

## Historical archive service

Add a dedicated service whose only eligible input is a row that reclassifies as exactly `historical_ambiguous` while locked.

For each candidate during apply:

1. Start a database transaction.
2. Reload the row from the database with `lockForUpdate()`.
3. Load `order.invoice` and `activeDrafts`, plus any relations the classifier requires.
4. Reclassify at one captured evaluation time.
5. Skip unless the state is still exactly `historical_ambiguous`.
6. Record terminal lifecycle metadata only:
   - `released_at`: archival timestamp
   - `release_reason`: `historical_reconciliation_stock_neutral`
   - `release_note`: an explicit statement that historical reconciliation occurred without warehouse-stock adjustment
   - `released_by`: null/system unless the existing command audit convention provides a real actor

The service does not call `WarehouseStockService::change()`, write `warehouse_stocks.quantity`, create `stock_movements`, invoke the normal stale-release service, or rebuild reservation projections. The existing released lifecycle is appropriate because the classifier already treats either release terminal field as closed and the reason distinguishes archival from stock-returning release.

Repeated archival is idempotent: an already archived row classifies as released and is skipped. Selection is not authorization; locking and canonical reclassification are mandatory even for explicit IDs.

## Archive command

Add `inventory:archive-historical-reservations` with:

- Default/dry-run behavior through `--dry-run` (accepted explicitly and used when `--apply` is absent).
- Mutation only when both `--apply` and `--confirm` are present.
- An explicit candidate selector: `--ids=` for reviewed IDs or `--all-historical` for the complete canonical historical population.
- Mutual-exclusion and missing-confirmation validation with non-zero exit status.
- A summary of selected, eligible, archived, skipped, and total quantity, clearly stating that the operation is stock-neutral.

Dry-run classifies fresh rows with required relations but writes nothing. Apply delegates each locked candidate to the archive service. No production archive is run during this task.

## History UI and auditability

Archived rows disappear from Current/Actionable/Review because their terminal fields make their canonical state `released`. They remain queryable in the history tab and detail/audit views.

Rows with `release_reason=historical_reconciliation_stock_neutral` display the exact label:

> پاکسازی تاریخی — بدون تغییر موجودی

Normal released rows retain their normal release labels. History copy explicitly distinguishes stock-neutral historical reconciliation from a stock-returning release. If the existing activity-log convention can record the archival event without side effects, the service records a dedicated audit action and the detail page includes it.

## Safety and regression tests

After Task 0 passes, add focused tests proving:

- `active_valid` is active/protected and never releasable or labeled for cleanup/release.
- `historical_ambiguous` is Review/archive-only.
- `official_active` is active.
- Only `temporary_stale_releasable` exposes normal release.
- Dashboard actionable counts are canonical.
- Default results exclude released, consumed, invoice-linked, and review/actionable clutter.
- Archive dry-run writes nothing.
- Apply closes only locked, reclassified historical ambiguous rows.
- Ineligible canonical states, including `legacy_safe`, are skipped.
- Archive is idempotent and rows remain queryable in history/audit.
- Archive produces zero stock movements and zero reserved-projection mutation.
- Before and after archival, both the sum of `warehouse_stocks.quantity` for the central warehouse and every affected product/variant warehouse quantity are byte-for-byte/numerically identical.

## Verification sequence

1. Run Task 0 lifecycle tests as the gate.
2. Run focused Phase 4 UI/archive tests during test-driven implementation.
3. Run all identified Phase 1, Phase 2, and Phase 3 reservation suites.
4. Run the complete automated test suite.
5. Run PHP syntax checks for changed PHP files and any repository-standard frontend/build checks affected by the changes.
6. Run `git diff --check` and review the final diff for unrelated changes.

The implementation report will include production-safe commands for a pre-archive classification/quantity snapshot, archive dry-run, central and per-variant stock checksums/totals, deliberately confirmed apply, post-archive audit, reserved-projection dry-run, and stock-movement verification. These commands are returned for later operator review and are not executed against production.
