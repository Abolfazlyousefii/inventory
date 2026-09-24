# Product Variant Integrity Phase 5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire implicit electrical black/white variants, isolate product editing from sales-status mutations, establish safe Base Variant and purchase semantics, and provide fail-closed audit/cleanup tooling for unused synthetic variants.

**Architecture:** Product behavior changes are protected first by controller-level contract tests. Origin classification, reference/usage safety, Base Variant eligibility, purchase resolution, and destructive cleanup are separate services; cleanup consumes the already-tested classifier and audit layers and revalidates both under a row lock. Physical warehouse stock and canonical reservations remain authoritative, while product/variant stock and price fields remain projections.

**Tech Stack:** PHP 8+, Laravel, Eloquent transactions and row locks, schema metadata (`information_schema`/SQLite pragmas), Artisan commands, Pest/PHPUnit, Blade/HTTP feature tests.

**Spec:** `docs/superpowers/specs/2026-09-22-product-variant-integrity-phase-5-design.md`

## Global Constraints

- Work directly on local `main` based on `6b55271d`; do not create a Phase 5 branch.
- The pre-existing untracked `.claude/` directory is an allowed exception and must not be read, modified, added, staged, committed, deleted, renamed, moved, or ignored.
- Do not commit, push, deploy, or mutate production.
- Do not move, merge, invent, or reassign physical stock or business history.
- `warehouse_stocks.quantity` is physical authority; canonical reservation queries are reserved authority.
- Normal product create/edit/update must not mutate `product_variants.is_active`, `product_variants.sales_enabled`, or `products.is_sellable` through structure reconciliation.
- `--all-safe` may delete only `PROVEN_SYNTHETIC AND SAFE_TO_REMOVE`; probable rows require explicit reviewed IDs and full locked revalidation.
- No sell or buy price may be guessed.
- Preserve Phase 1–4 reservation behavior.
- Every normal commit step is replaced by a diff/review checkpoint.

## Review Focus

- A legacy simple product with a product-level warehouse row—even quantity zero plus ambiguous historical ownership—must be classified deterministically and never receive a Base Variant when ownership would need reassignment; Task 7 tests positive, zero-but-referenced, and absent rows.
- A variant referenced through a conventionally named column without a declared foreign key must fail closed as an unknown reference; Task 5 creates such a table and tests discovery.
- An explicit probable ID that becomes referenced between dry-run and apply must be skipped after locked revalidation; Task 9 tests the race boundary.
- A purchase payload with a null/zero/stale variant ID for a multi-variant product must fail rather than resolve by ordering; Task 8 tests null, zero, foreign-product, inactive, and reordered fixtures.
- A product with only zero/non-positive usable prices must remain explicitly zero/missing without borrowing another product’s price or changing sellability; Task 3 tests both isolation and status preservation.

---

### Task 0: Lock the Product/Variant Integrity Contract

**Files:**
- Create: `tests/Feature/ProductVariantIntegrityContractTest.php`
- Create: `docs/superpowers/audits/2026-09-22-product-variant-integrity-task-0.md`
- Read: `app/Http/Controllers/ProductController.php`
- Read: `app/Http/Controllers/PurchaseController.php`
- Read: `app/Services/DefaultProductDesignService.php`
- Read: `app/Services/ProductVariantStructureService.php`
- Read: explicit sales-status services/commands and all variant-reference migrations

**Interfaces:**
- Consumes: existing product store/update routes, purchase store/update routes, `WarehouseStockService`, `ReservationQueryService`, and current explicit product-sales-status workflows.
- Produces: reusable test fixtures/snapshot helpers plus a read-only call-path inventory; no product code.

- [ ] **Step 1: Record the clean baseline without touching `.claude/`**

Run:

```powershell
git branch --show-current
git rev-parse --short HEAD
git status --short
```

Expected: `main`, `6b55271d`, and only `?? .claude/` plus the two Phase 5 documentation files created during planning.

- [ ] **Step 2: Write authority and mutation-snapshot helpers**

Create test helpers that read physical and canonical values independently:

```php
function phaseFiveWarehouseQuantities(int $variantId): array
{
    return WarehouseStock::query()->where('product_variant_id', $variantId)
        ->orderBy('warehouse_id')->pluck('quantity', 'warehouse_id')
        ->map(fn ($quantity) => (int) $quantity)->all();
}

function phaseFiveCanonicalReserved(int $productId, int $variantId): int
{
    return (int) app(ReservationQueryService::class)
        ->quantitiesByVariant($productId, [$variantId])->get($variantId, 0);
}

function phaseFiveSalesState(Product $product): array
{
    return [
        'product' => (bool) $product->fresh()->is_sellable,
        'variants' => $product->variants()->orderBy('id')
            ->get(['id', 'is_active', 'sales_enabled'])->toArray(),
    ];
}
```

- [ ] **Step 3: Add current-contract characterization tests**

Pin explicit sales-status workflows as the only legitimate status writers, purchase physical mutations to the selected warehouse row, and reservation authority to canonical queries. Do not assert the undesired automatic black/white/deactivation behavior as permanent; the tests should instead snapshot unrelated fields around explicit workflows.

- [ ] **Step 4: Write the Task 0 audit document**

Document exact call sites and classify them as implicit product editing, explicit user workflow, confirmed repair command, projection update, or read-only audit. Include the two default-color calls, the one normal-edit deactivation call, both summary implementations, purchase validation/resolution, and every discovered schema reference.

- [ ] **Step 5: Run the contract tests**

Run:

```powershell
php artisan test tests/Feature/ProductVariantIntegrityContractTest.php --stop-on-failure
```

Expected: pass; these tests establish authorities and explicit workflows without requiring Phase 5 behavior yet.

- [ ] **Step 6: Review checkpoint**

Run `git diff -- tests/Feature/ProductVariantIntegrityContractTest.php docs/superpowers/audits/2026-09-22-product-variant-integrity-task-0.md` and verify no production code changed.

### Task 1: Stop Future Automatic Black/White Creation

**Files:**
- Modify: `app/Http/Controllers/ProductController.php:357-532,614-769`
- Preserve: `app/Services/DefaultProductDesignService.php`
- Create: `tests/Feature/ProductDefaultVariantRetirementTest.php`

**Interfaces:**
- Consumes: existing product create/update requests and explicit variant synchronization.
- Produces: product create/update behavior with no implicit category color side effects.

- [ ] **Step 1: Write failing create/edit regression tests**

Create an electrical root/category descendant and submit valid product create/update requests. Assert:

```php
expect($product->variants()->whereIn('variety_name', ['مشکی', 'سفید'])->count())->toBe(0);
```

Cover create, category change into electrical, ordinary edit, and two repeated edits. Assert no `electric_default_color_created` activity and no extra warehouse row.

- [ ] **Step 2: Verify red**

Run `php artisan test tests/Feature/ProductDefaultVariantRetirementTest.php --stop-on-failure`.
Expected: create/update cases fail because the two controller call sites create defaults.

- [ ] **Step 3: Remove only the automatic call sites**

Delete the two `ensureElectricDefaultColors()` calls and unused controller import/local variable. Keep `DefaultProductDesignService` intact for historical evidence support.

- [ ] **Step 4: Verify green and existing product-create tests**

Run:

```powershell
php artisan test tests/Feature/ProductDefaultVariantRetirementTest.php tests/Feature/ProductPageAccessRegressionTest.php --stop-on-failure
```

- [ ] **Step 5: Review checkpoint**

Confirm `rg -n "ensureElectricDefaultColors" app/Http/Controllers app/Observers` returns no production-flow call sites.

### Task 2: Stop Automatic Deactivation During Normal Product Editing

**Files:**
- Modify: `app/Http/Controllers/ProductController.php:614-769`
- Preserve as audit-only: `app/Services/ProductVariantStructureService.php:140-169`
- Create: `tests/Feature/ProductEditSalesStatusIsolationTest.php`
- Reuse: `tests/Feature/ProductSalesStatusManagementTest.php`

**Interfaces:**
- Consumes: `phaseFiveSalesState()` and normal product update route.
- Produces: editing that preserves product/variant sales-state fields; explicit status workflows remain unchanged.

- [ ] **Step 1: Write the failing edit matrix**

Use a data provider for name, category, image, price, models/design metadata, and an unrelated field. Give the product an active/sales-enabled historical variant that the submitted structure considers invalid. For each edit assert before/after equality of `is_sellable`, `is_active`, and `sales_enabled`.

- [ ] **Step 2: Add explicit-workflow compatibility tests**

Use the existing product-deactivation service/route and assert an authorized explicit action still changes the intended status and records its audit document.

- [ ] **Step 3: Verify red**

Run `php artisan test tests/Feature/ProductEditSalesStatusIsolationTest.php --stop-on-failure`.
Expected: metadata/category cases fail at `deactivateInvalidVariants()`.

- [ ] **Step 4: Remove structural mutation from normal update**

Remove the `deactivateInvalidVariants($product)` call. Keep the service method for read-only audit/legacy explicit callers; do not replace it with another status mutation.

- [ ] **Step 5: Verify edit isolation and sales-status suites**

Run:

```powershell
php artisan test tests/Feature/ProductEditSalesStatusIsolationTest.php tests/Feature/ProductSalesStatusManagementTest.php tests/Feature/ProductSalesStatus --stop-on-failure
```

- [ ] **Step 6: Review checkpoint**

Confirm the normal store/update methods contain no writes to `is_active`, `sales_enabled`, or `is_sellable` beyond explicitly submitted product creation state.

### Task 3: Harden the Product Summary Projection

**Files:**
- Modify: `app/Services/ProductVariantStructureService.php:172-182`
- Modify: `app/Http/Controllers/ProductController.php:1108-1112` only if delegation changes
- Modify: `app/Http/Controllers/PurchaseController.php:1255-1262` only if delegation changes
- Create: `tests/Feature/ProductSummaryProjectionTest.php`
- Extend: `tests/Feature/PriceIntegrityAuditCommandTest.php`

**Interfaces:**
- Produces: `ProductVariantStructureService::recalculateProductSummary(Product $product): void` as the single shared projection method.
- Contract: positive usable variant prices only; no status writes; no cross-product inference.

- [ ] **Step 1: Write failing price/status tests**

Create a product priced 100 with a valid positive-price variant and an active zero-price synthetic-looking black/white row. Recalculate and assert price remains 100. Add cases for inactive positive rows, structurally unusable placeholders, no positive price (result zero), another product with a positive price (must not leak), and unchanged `is_sellable`.

- [ ] **Step 2: Write deletion-neutral summary test**

Delete only a zero-stock/unreferenced zero-price fixture directly inside the test transaction, recalculate, and assert the valid product price remains unchanged.

- [ ] **Step 3: Verify red**

Run `php artisan test tests/Feature/ProductSummaryProjectionTest.php --stop-on-failure`.
Expected: at least the placeholder/structure edge case exposes current divergence.

- [ ] **Step 4: Centralize the projection rule**

Refine `recalculateProductSummary()` to select positive prices from usable current variants, calculate stock/reserved using the established canonical projection population, and never write sales-status fields. Remove any live dependency on the private default-design summary by virtue of Task 1 call removal; do not add guessed fallback prices.

- [ ] **Step 5: Verify focused price tests**

Run:

```powershell
php artisan test tests/Feature/ProductSummaryProjectionTest.php tests/Feature/PriceIntegrityAuditCommandTest.php tests/Feature/PurchaseValidationTest.php --stop-on-failure
```

- [ ] **Step 6: Review checkpoint**

Search all product-summary call sites and confirm they delegate to the shared positive-price contract.

### Task 4: Synthetic Default Variant Evidence Classifier

**Files:**
- Create: `app/Services/SyntheticDefaultVariantClassifier.php`
- Create: `tests/Feature/SyntheticDefaultVariantClassifierTest.php`
- Read/reuse: `app/Services/DefaultProductDesignService.php`

**Interfaces:**
- Produces constants `PROVEN_SYNTHETIC`, `PROBABLE_SYNTHETIC`, `NOT_SYNTHETIC`.
- Produces `classify(ProductVariant $variant): array{class:string,reasons:array<int,string>,evidence:array<string,mixed>}`.

- [ ] **Step 1: Write the evidence-matrix tests**

Test exact matching activity evidence, wrong product/variant IDs, malformed properties, manual contrary evidence, electrical ancestry, normalized Persian black/white names, exact legacy code shape, non-electrical lookalikes, explicit model variants, and arbitrary colored variants.

- [ ] **Step 2: Verify red**

Run `php artisan test tests/Feature/SyntheticDefaultVariantClassifierTest.php --stop-on-failure`.
Expected: class missing.

- [ ] **Step 3: Implement read-only classification**

Load product/category ancestry and relevant activity logs. Match proven evidence first. Require every structural signal for probable classification and fail to `NOT_SYNTHETIC` on ambiguity/contrary evidence. Perform no writes.

- [ ] **Step 4: Verify green and mutation absence**

Run the focused test and compare serialized product, variant, warehouse-stock, and activity tables before/after classification.

- [ ] **Step 5: Review checkpoint**

Confirm category/name/code evidence cannot return `PROVEN_SYNTHETIC`.

### Task 5: Variant Usage and Schema Reference Audit

**Files:**
- Create: `app/Services/VariantReferenceDiscoveryService.php`
- Create: `app/Services/VariantUsageAuditService.php`
- Create: `tests/Feature/VariantReferenceDiscoveryServiceTest.php`
- Create: `tests/Feature/VariantUsageAuditServiceTest.php`

**Interfaces:**
- `VariantReferenceDiscoveryService::discover(): Collection<int,array{table:string,column:string,known:bool}>`.
- `VariantUsageAuditService::audit(ProductVariant $variant): array` containing every report column/count, `other_reference_tables`, `blocking_reasons`, and `safe_to_remove`.
- Consumes `ReservationQueryService::quantitiesByVariant()` for canonical reserved quantity.

- [ ] **Step 1: Write metadata-discovery tests**

Assert declared foreign keys and conventional columns are found for purchase, invoice, preinvoice, reservations, movements, transfers, counts, inbound, returns, price changes, warehouse maps, commissions, and seller documents. In the test database create a temporary table with `product_variant_id` but no FK and assert it is returned as `known=false`.

- [ ] **Step 2: Verify discovery test red**

Run `php artisan test tests/Feature/VariantReferenceDiscoveryServiceTest.php --stop-on-failure`.

- [ ] **Step 3: Implement portable metadata discovery**

Use the current connection driver: SQLite `PRAGMA foreign_key_list` plus `PRAGMA table_info`; MySQL `information_schema.KEY_COLUMN_USAGE` plus `information_schema.COLUMNS`. Limit conventional scanning to exact `variant_id`, `product_variant_id`, and `created_variant_id`; normalize/deduplicate table-column pairs.

- [ ] **Step 4: Write one blocking test per usage class**

Build a safe zero-evidence variant, then independently add non-zero warehouse stock, cached stock, canonical reservation, cached reserved, purchase, invoice, preinvoice, historical reservation, movement, transfer, count, location, inbound, return, price-change, deactivation, commission/seller, external mapping, audit evidence, and unknown-table reference. Each must make `safe_to_remove=false` with a literal blocking reason.

- [ ] **Step 5: Implement fail-closed usage audit**

Query authoritative stock and reservations directly, query each known semantic reference, consume metadata discovery, and treat unknown references as blockers. Return counts rather than booleans so reports remain auditable. Do not mutate projections.

- [ ] **Step 6: Verify focused services**

Run:

```powershell
php artisan test tests/Feature/VariantReferenceDiscoveryServiceTest.php tests/Feature/VariantUsageAuditServiceTest.php --stop-on-failure
```

- [ ] **Step 7: Review checkpoint**

Compare the semantic registry against all migration/model matches for `variant_id`, `product_variant_id`, and `created_variant_id`; every discovered unknown must block.

### Task 6: Read-Only Production Audit Command

**Files:**
- Create: `app/Services/SyntheticDefaultVariantAuditService.php`
- Create: `app/Console/Commands/AuditSyntheticDefaultVariants.php`
- Create: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

**Interfaces:**
- Consumes classifier and usage audit.
- Produces command `inventory:audit-synthetic-default-variants` and summary/report rows specified by the design.

- [ ] **Step 1: Write failing command output tests**

Create proven-safe, proven-purchase-protected, probable, stock-protected, reservation-protected, missing-base, zero-price-risk, and unexplained-inactive fixtures. Assert exact summary counters and required columns.

- [ ] **Step 2: Add write-query guard test**

Follow the repository’s price/reservation audit guard pattern. Execute the command while listening for SQL and fail if any insert/update/delete/DDL statement occurs. Snapshot all relevant tables before/after.

- [ ] **Step 3: Verify red**

Run `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php --stop-on-failure`.

- [ ] **Step 4: Implement the audit service and command**

Stream/chunk variants, classify origin, audit usage, and aggregate counts. Support safe filters such as `--product-id`, `--variant-id`, and output format only if they follow existing command conventions. Never add apply/repair behavior.

- [ ] **Step 5: Verify green**

Run the focused command test and `php artisan inventory:audit-synthetic-default-variants --help` against the test/development environment only.

- [ ] **Step 6: Review checkpoint**

Confirm the command class contains no `save`, `update`, `delete`, stock service, projection rebuild, or movement creation path.

### Task 7: Canonical Base Variant Service

**Files:**
- Create: `app/Services/CanonicalBaseVariantService.php`
- Create: `tests/Feature/CanonicalBaseVariantServiceTest.php`

**Interfaces:**
- Produces `inspect(Product $product): array{state:string,variant:?ProductVariant,blocking_reasons:array<int,string>}`.
- Produces `createIfSafe(Product $product): array{state:string,variant:?ProductVariant,created:bool,blocking_reasons:array<int,string>}`.
- State constants include `AVAILABLE`, `CREATED`, `NOT_SIMPLE`, and `BLOCKED_FOR_REVIEW='blocked_for_base_variant_review'`.

- [ ] **Step 1: Write deterministic identity/idempotency tests**

For a genuinely simple product with no stock/history, assert creation yields null model, `0000`, code `<product-code>00000`, clear base name, active state, product-derived explicit sales flag, zero stock/reserved, and positive same-product price only when present. Repeat and assert one row.

- [ ] **Step 2: Write every blocking preflight test**

Assert no creation for product-level warehouse stock, synthetic stock, another variant’s stock/history, purchase/invoice/preinvoice/reservation/movement/transfer/count/inbound/return/price-change/site mapping, unknown reference, ambiguous legacy ownership, non-simple metadata, and duplicate/conflicting code.

- [ ] **Step 3: Verify red**

Run `php artisan test tests/Feature/CanonicalBaseVariantServiceTest.php --stop-on-failure`.

- [ ] **Step 4: Implement inspect and locked creation**

Use `VariantUsageAuditService`/reference discovery for ownership evidence, then lock the product and repeat inspection before create. Never call `WarehouseStockService::change()` and do not create warehouse rows with quantity. Return blocked state instead of partially creating.

- [ ] **Step 5: Verify physical/history neutrality**

Snapshot all warehouse quantities, stock movements, business reference rows, and reservation totals around blocked and successful creation. The successful empty product gains only one zero-projection variant; all authorities remain unchanged.

- [ ] **Step 6: Review checkpoint**

Confirm there is no stock migration, business-row update, inferred buy price, or cross-product sell-price query.

### Task 8: Purchase Variant Resolution Safety

**Files:**
- Create: `app/Services/PurchaseVariantResolver.php`
- Modify: `app/Http/Controllers/PurchaseController.php:476-640,900-1160`
- Modify: `resources/views/purchases/create.blade.php` only if the explicit Base Variant state needs UI copy
- Create: `tests/Feature/PurchaseVariantResolutionSafetyTest.php`
- Extend: `tests/Feature/PurchaseValidationTest.php`

**Interfaces:**
- Consumes `CanonicalBaseVariantService::inspect()`.
- Produces `resolve(Product $product, mixed $variantId): ProductVariant` or a field-specific `ValidationException`.

- [ ] **Step 1: Write failing resolver/controller tests**

Assert explicit real variant selection succeeds; a simple product resolves only its existing canonical Base Variant; missing base returns the explicit review error; multi-variant null/zero fails; foreign/inactive/ineligible IDs fail; reordered IDs do not change resolution; black/white rows are never implicit fallbacks.

- [ ] **Step 2: Write stock/price isolation tests**

Submit purchase create and update for one selected variant. Assert only its exact warehouse row changes, only its buy/sell price fields follow purchase rules, stock movement references that ID, and every unrelated variant/warehouse row is byte-for-byte unchanged.

- [ ] **Step 3: Verify red**

Run `php artisan test tests/Feature/PurchaseVariantResolutionSafetyTest.php --stop-on-failure`.

- [ ] **Step 4: Implement explicit resolution**

Normalize null/zero as missing, reject missing for multi-variant products, resolve an existing safe Base Variant for genuinely simple products, and never create a Base Variant as an incidental purchase fallback. Replace direct structural-exists validation with resolver output while preserving request error keys.

- [ ] **Step 5: Verify purchase suites**

Run:

```powershell
php artisan test tests/Feature/PurchaseVariantResolutionSafetyTest.php tests/Feature/PurchaseValidationTest.php tests/Feature/PurchaseExportSummaryTest.php tests/Feature/PurchaseJalaliFilterQueryTest.php --stop-on-failure
```

- [ ] **Step 6: Review checkpoint**

Search purchase code for `first()`, `oldest()`, `min(id)`, black/white literals, or collection index fallback in variant resolution and prove none authorize a target.

### Task 9: Fail-Closed Synthetic Cleanup

**Files:**
- Create: `app/Services/SyntheticDefaultVariantCleanupService.php`
- Create: `app/Console/Commands/CleanupSyntheticDefaultVariants.php`
- Create: `tests/Feature/CleanupSyntheticDefaultVariantsCommandTest.php`
- Create: `tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php`

**Interfaces:**
- Consumes classifier, usage audit, and shared product summary projection.
- Produces dry-run-default `inventory:cleanup-synthetic-default-variants` with `--apply --confirm` and exactly one of `--ids`/`--all-safe`.
- Produces per-row `DELETED`/`SKIPPED` results with evidence and blockers.

- [ ] **Step 1: Write command safety/selector tests**

Assert no selector, both selectors, apply without confirm, confirm without apply, duplicates/invalid IDs, and contradictory dry-run/apply flags fail without writes. Assert dry-run reports but changes nothing.

- [ ] **Step 2: Write authorization matrix tests**

Assert `--all-safe` selects only proven+safe, never probable. Assert explicit IDs may consider probable+safe but skip not-synthetic, protected, missing, and changed rows.

- [ ] **Step 3: Write locked revalidation/race tests**

Use a service seam or transaction hook to add a purchase/reference between preview and apply, then assert locked re-audit skips deletion. Assert a classification change also skips. Repeat apply and assert idempotency.

- [ ] **Step 4: Write neutrality and history-preservation tests**

Snapshot total/per-warehouse quantities, canonical reserved values, cached projections, movements, purchases, invoices, preinvoices, reservations, transfers, counts, inbound, returns, price changes, mappings, and activity/history. Only the safe variant and its zero-only warehouse row may disappear; no other value changes. Product price remains valid and `is_sellable` unchanged.

- [ ] **Step 5: Verify red**

Run:

```powershell
php artisan test tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php tests/Feature/CleanupSyntheticDefaultVariantsCommandTest.php --stop-on-failure
```

- [ ] **Step 6: Implement locked cleanup service**

For each ID, open a transaction, `lockForUpdate()` the variant, load required relations, rerun classifier and usage audit, enforce selector provenance, delete only zero warehouse rows if required, delete the still-safe variant, then call the shared summary projection. Never call stock-change APIs or mutate history.

- [ ] **Step 7: Implement dry-run-first command**

Build `--all-safe` candidates from proven evidence, while explicit IDs remain broad enough to report ineligible rows. Selection is not authorization; the service revalidates every apply row.

- [ ] **Step 8: Verify green and forbidden-call scan**

Run focused tests, then:

```powershell
rg -n "WarehouseStockService::change|StockMovement::create|rebuildForProducts|increment\(|decrement\(" app/Services/SyntheticDefaultVariantCleanupService.php app/Console/Commands/CleanupSyntheticDefaultVariants.php
```

Expected: no forbidden mutation call.

- [ ] **Step 9: Review checkpoint**

Confirm probable rows cannot enter `--all-safe`, and explicit probability never bypasses the full locked usage audit.

### Task 10: Complete Regression and Safety Validation

**Files:**
- Modify only failing tests whose assertions encode the retired implicit behavior, and only after confirming the new specification intentionally replaces them.
- Do not modify `.claude/`.

**Interfaces:**
- Consumes all Phase 5 services/commands and existing product, purchase, warehouse, sales-status, price, and reservation suites.
- Produces final evidence and diff for human review; no deployment or production mutation.

- [ ] **Step 1: Run the Phase 5 focused suite**

Run:

```powershell
php artisan test tests/Feature/ProductVariantIntegrityContractTest.php tests/Feature/ProductDefaultVariantRetirementTest.php tests/Feature/ProductEditSalesStatusIsolationTest.php tests/Feature/ProductSummaryProjectionTest.php tests/Feature/SyntheticDefaultVariantClassifierTest.php tests/Feature/VariantReferenceDiscoveryServiceTest.php tests/Feature/VariantUsageAuditServiceTest.php tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/CanonicalBaseVariantServiceTest.php tests/Feature/PurchaseVariantResolutionSafetyTest.php tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php tests/Feature/CleanupSyntheticDefaultVariantsCommandTest.php --stop-on-failure
```

- [ ] **Step 2: Run existing product/purchase/sales-status/price/warehouse suites**

Build the file list with `rg --files tests` and run all tests matching `Product`, `Purchase`, `Price`, `WarehouseStock`, and `ProductSalesStatus`. Report exact counts.

- [ ] **Step 3: Run all Phase 1–4 reservation regressions**

Run every test file matching `Reservation.*Test.php`, including `ReservationLifecycleContractPhaseFourTest.php`. Any lifecycle failure stops Phase 5 completion.

- [ ] **Step 4: Run the full suite**

Run `php artisan test --compact` and report passed, failed, skipped, assertions, and duration.

- [ ] **Step 5: Run PHP syntax checks**

Collect every changed/new `.php` file outside `.claude/` from Git status and run `php -l` on each. Expected: no syntax errors.

- [ ] **Step 6: Run diff/static safety checks**

Run:

```powershell
git diff --check
git diff --stat
git status --short
```

Inspect the final diff for stock changes, guessed prices, status mutations, business-history deletion, and unrelated files.

- [ ] **Step 7: Verify the `.claude/` exception**

Confirm `git status --short -- .claude` is still exactly `?? .claude/` and `git diff -- .claude` is empty. Do not enumerate or read its contents.

- [ ] **Step 8: Prepare the review report and stop**

Report in this order: exact root causes; number/type of black/white creation paths; every automatic deactivation path; classifier/usage design; Base Variant blocks; focused, regression, full-suite, syntax, and diff evidence. State that no production command, commit, push, or deployment occurred, then stop for review.
