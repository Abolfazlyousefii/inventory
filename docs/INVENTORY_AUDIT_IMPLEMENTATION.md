# Inventory audit implementation and verification log

**Audit basis:** `ariya-inventory-backend-audit-1405-08-03(3).md`, dated 2026-10-25.  
**Project input:** `inv md(8).zip`, received 2026-11-01.  
**Safety:** No real inventory, reservations, pricing, site database, or financial records were modified. No live HTTP calls were made.

## Status legend

- **Implemented in supplied source:** code appears to address the audit finding; not a claim of deployment or end-to-end acceptance.
- **Standalone verified:** isolated PHP regression checks run and passed locally.
- **Integration pending:** Laravel/database/external concurrency tests still need a complete runtime and controlled test database.
- **Operational pending:** depends on real warehouse/business data or management sign-off, so cannot be performed on a source-only ZIP.

## Package 1 — prerequisite to applying a physical stock count (P0)

### INV-01 — protect automatic-bundle components from double counting

**Already present in supplied ZIP:**

- Draft item snapshots include ordinary reserved stock, free bundle allocations, reserved bundle components, and site-held components.
- Physical quantity derives from those disjoint categories; final free stock subtracts protected quantities.
- Finalization compares snapshots to current quantities, locks bundle product/allocations, prevents repeated finalization, and uses a database transaction for stock and movement records.

**New hardening in this package:**

- Reject negative reserved-bundle quantities, overflowed recipe multiplication, and overflowed sums of bundle allocations or site holds.
- Validate nonnegative and non-overflowing physical count inputs before calculating free stock.
- Add standalone negative/overflow regression cases. Original 10 physical / 2 bundle-protected / 8 free fixture remains in the test suite.
- Extend `StockCountLifecycleSafetyTest.php` to include the bundle/site-hold schema and database-backed acceptance cases for 10/2/8, a stale allocation, and site holds.

**Result:** arithmetic standalone checks **passed**. Laravel lifecycle integration cases **written, not executed here**.

### INV-02 — disable legacy repair command apply mode

**Already present in supplied ZIP:** `InventoryRepairCorruptionCommand` rejects `--apply` before the diagnostic service can run, regardless of `--force`, `--backup`, or `--dry-run`. Standalone tests for three apply combinations **passed**.

**Remaining:** Verify against a complete Laravel application and keep report-only execution independent of mutable inventory. Do **not** use this tool as the basis for production stock corrections.

### Count-scope and safe cutover (operational pending)

Warehouse/finance owners must agree on the physical inclusion/exclusion of unsent sales, reserved stock, bundle components, unreceived returns, personnel stock and other warehouses. The audit also requires a usable backup and documented cutover time. These steps **cannot be completed from source code alone**.

## Package 2 — consistent stock sent to the site (P1)

### INV-04 — constrain sellable warehouse scope

**Already present in supplied ZIP:** the two inspected outbound sync paths used the central warehouse rather than summing every warehouse.

**New hardening in this package:**

- Added `inventory.site_sellable_warehouse_ids` configuration, default `[]` = **central warehouse only**.
- Both `AriyajanebiSyncService` and `SyncProductsToSite` now use `WarehouseStockService::siteSellableWarehouseIds()`.
- Only explicitly configured, existing, active warehouses are accepted; invalid/inactive IDs throw instead of broadening availability.
- Added standalone scope tests and additional Laravel integration coverage for explicitly allowed and inactive warehouses.
- Fixed the API retry path for **automatic bundles**: it now sends calculated, protected-component bundle availability instead of a direct warehouse-row sum, consistent with scheduled site synchronization.
- Added a standalone failing-before-fix scenario and a Laravel feature regression test for the automatic-bundle case (three available kits, despite an unrelated stock row of twenty).

**Result:** standalone scope and retry tests **passed**, including the automatic-bundle snapshot; Laravel/real-site acceptance **pending**.

**Configuration:** edit `config/inventory.php` to specify the **authorized** warehouse IDs, e.g. `[1]` for ID 1, only after verifying the deployment's actual warehouse IDs. Do not list returns/personnel warehouses unless they genuinely serve site sales. Empty list retains the dynamically identified central warehouse.

### INV-03 — retry with current stock and price

**Already present in supplied ZIP:** retries reconstruct the outgoing price and stock from current ERP records rather than using old saved payloads; a conditional log claim prevents two workers from claiming **the same log** at once. Standalone tests using historical 20/2000 versus current 5/1000 **passed**.

**Still pending:** A full solution to **cross-log out-of-order HTTP deliveries**, endpoint-side version ordering/idempotency, and two-worker network-failure acceptance tests. Even if every retry loads fresh values, two in-flight requests can arrive at the external site in reverse order. This cannot be declared solved without a destination-enforced ordering/version contract or an equivalent validated single-flight design.

## Package 3 — historical reconciliation and ongoing checks (operational pending)

The audit listed four ambiguous historical reservations (230 units), 26 central-stock variants with zero price, 106 noncentral zero-price stock lines, 40 legacy aggregate duplicate groups, and 2,539 older mismatching ledger rows. These are **historical audit snapshots**, not values verified on the date of this code review. They require a fresh **read-only** inventory audit, source-document investigations, and owner decisions. No broad auto-release, auto-deletion, price assignment, stock zeroing, or ledger rewrite was performed.

The audit's suggested read-only command is:

```bash
php artisan inventory:audit-stock-reservation-integrity --summary
```

Do not run any command's mutating mode on production on the basis of this report.

## Verification performed on this ZIP

```text
php tests/standalone/run.php
12 stock count quantity assertions passed.
7 bundle allocation aggregation assertions passed.
3 stock count snapshot/presentation assertions passed.
3 legacy-repair apply guard assertions passed.
5 site retry snapshot assertions passed.
10 sellable warehouse scope assertions passed.
All standalone regression probes passed.

PHP lint: 1,401 PHP files inspected; 0 syntax errors.
```

**Critical limitation:** The supplied ZIP has neither `artisan`, nor `vendor/autoload.php`, nor installed Composer. Consequently the Laravel feature suite, migrations, HTTP integration, and MySQL concurrency verification were **not** run. Passing standalone tests are not a substitute for these checks.

## Required release gates in a complete, isolated checkout

1. In a **complete Laravel checkout**, install exactly the project's locked dependencies and configure a **dedicated disposable test database**, not a production clone containing sensitive live values. Review `tests/TestCase.php`'s SQLite in-memory safeguards before running framework tests.
2. Run the targeted integration tests:

   ```bash
   php artisan test --filter=StockCountLifecycleSafetyTest
   php artisan test --filter=AriyajanebiSyncRetrySafetyTest
   php artisan test --filter=InventoryRepairCorruptionCommandTest
   ```

3. Run all inventory-related tests, then the full project suite (`php artisan test`). Investigate all failures; never weaken a test merely to report a green build.
4. In a **separately provisioned, isolated MySQL test environment**, validate duplicate finalization, concurrent reservations, bundle allocation edits, stock transfers, transaction rollback, and the site retry race. The included `tests/integration/inventory-concurrency.php` contains an explicit database naming/port guard; read it before use.
5. Mock the destination endpoint to test the 20→5 quantity and 2000→1000 price scenario, including two workers and out-of-order delivery. Define/verify the endpoint versioning or serialized-delivery contract before concluding INV-03 is fully accepted.
6. Compare a small, authorized sample of destination stock against the central ERP warehouse after disabling/re-enabling synchronization in staging. Investigate mismatches and get warehouse/finance acceptance of count scope, backup, and cutover handling.
7. Only after all gates pass should changes be reviewed for deployment. Production stocktaking and historical data corrections are **not** approved by this file.

## Final status

This is a **source-level hardening and regression-test delivery**, not a completion certificate for the October 25 audit. The new standalone tests and syntax checks pass. Framework integration, MySQL/concurrency, external ordering, historical reconciliation, and physical stocktake procedures remain open.
