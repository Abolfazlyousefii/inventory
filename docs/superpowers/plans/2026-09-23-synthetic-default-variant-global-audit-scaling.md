# Synthetic Default Variant Global Audit Scaling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The user explicitly requires native/local execution and forbids commits.

**Goal:** Make the complete synthetic-default-variant audit scale with bounded global ActivityLog scans and chunk-batched usage queries while preserving exact read-only and cleanup semantics.

**Architecture:** Collect only `variant_id => product_id` for the requested scope, stream ActivityLog evidence once into compact scalar maps, and reuse that immutable complete index while ProductVariant models are loaded in 200-row chunks. Classify candidates from the global index, batch all usage/reference checks per candidate chunk, retain only report data, and expose phase/chunk progress plus a table-suppressing `--summary-only` option.

**Tech Stack:** PHP 8.2+, Laravel Eloquent/query builder, Artisan Console, Pest feature tests, SQLite test database, Laravel query listener.

**Spec:** `docs/superpowers/specs/2026-09-23-synthetic-default-variant-audit-scaling-design.md`

## Global Constraints

- Do not access or mutate production.
- Do not deploy, commit, push, add migrations, or change MySQL/PHP configuration.
- Do not read or modify `.claude/`; its existing untracked state is allowed.
- Preserve the command name, `--product-id` / `--variant-id` selectors, existing default table output, summary keys, and final completion message.
- `--summary-only` must perform the same complete classification and safety checks and suppress only the row table.
- Audit code must never write, create a Base Variant, create a StockMovement, or call stock-changing services.
- Cleanup must continue using locked, fresh, transaction-local `classify()` and `VariantUsageAuditService::audit()` calls, including the final recheck.
- Evidence/reference incompleteness and query failures must abort explicitly; they must never become zero evidence.
- ActivityLog and ProductVariant rows remain streamed/chunked; global retained memory is compact scalar state with `O(audited variants)` complexity.

## Review Focus

- An empty requested scope must complete without scanning all ActivityLog rows and must produce zero summaries.
- A `--variant-id` whose variant does not exist must stay scoped/empty rather than widening to the full audit.
- A ProductVariant deleted between identity collection and model chunk loading must not make the evidence index appear complete for a different scope; progress and totals must remain internally consistent.
- Aggregate reference queries must correctly quote discovered table/column identifiers and propagate failures for unusual but valid schema names.
- Summary-only mode must not accidentally skip usage auditing or exact `missing_base` computation merely because row rendering is disabled.

---

### Task 1: Global Complete Activity Evidence Index

**Files:**
- Modify: `app/Services/SyntheticDefaultVariantEvidenceService.php`
- Modify: `app/Services/SyntheticDefaultVariantEvidenceSnapshot.php`
- Modify: `app/Services/SyntheticDefaultVariantAuditService.php`
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`
- Modify: `tests/Feature/SyntheticDefaultVariantClassifierTest.php`
- Modify: `tests/Feature/VariantUsageAuditServiceTest.php`

**Interfaces:**
- Produces: `SyntheticDefaultVariantEvidenceService::loadScope(array<int,int> $variantProducts): SyntheticDefaultVariantEvidenceSnapshot`.
- Produces: immutable snapshot methods `variantProducts(): array<int,int>`, `creationLogId(int $productId, int $variantId): ?int`, and `activityReferenceCount(int $variantId): int`.
- Produces: `SyntheticDefaultVariantAuditService` scope query that selects only `id` and `product_id` before any ActivityLog scan.
- Consumes: existing exact proof parsing and per-log subject/property deduplication semantics.

- [ ] **Step 1: Add failing global-scan regressions**

Add Pest tests that create 10, 100, and 401 scoped ProductVariants, listen to SQL containing `activity_logs`, run the full audit service, and record both total ActivityLog query executions and unrestricted contrary-scan statements. Assert 10 and 100 have the same count, 401 does not multiply the full-scan family by three ProductVariant chunks, and no ActivityLog query originates after the ProductVariant processing callback begins. Include a 401+ fixture with no proof logs so classification crosses three 200-row chunks.

Also add tests that an empty/missing scoped variant performs no unrestricted ActivityLog scan, that subject+property references deduplicate to one, and that a loader whose `scanQuery()` returns `false` throws before returning a snapshot.

- [ ] **Step 2: Run the new tests and verify RED**

Run: `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/SyntheticDefaultVariantClassifierTest.php tests/Feature/VariantUsageAuditServiceTest.php`

Expected: FAIL because 401 variants trigger repeated ActivityLog scans and `loadScope()` / global orchestration do not exist.

- [ ] **Step 3: Implement the compact global evidence index**

Change the evidence loader to accept the complete scalar scope map. Return immediately for an empty map. Stream exact creation logs in ID chunks, filtered by scoped product IDs, and stream the complete non-neutral contrary set once in ID chunks. Retain only:

```php
array<int,int> $variantProducts;
array<int,int> $creationLogIdsByVariant;
array<int,int> $activityReferenceCounts;
```

Within each contrary log, build a temporary `array<int,true>` from direct subject and integer `properties.variant_id`, increment each matched scoped variant once, then release the model chunk. Construct the immutable snapshot only after every `chunkById()` returns `true`; otherwise throw `RuntimeException`. Snapshot methods must throw `LogicException` for out-of-scope IDs.

Change audit orchestration to collect `id => product_id` once using scalar query chunks before calling `loadScope()`. Do not retain ProductVariant models from scope collection and do not call the loader from inside the later ProductVariant callback.

- [ ] **Step 4: Run focused evidence tests and verify GREEN**

Run: `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/SyntheticDefaultVariantClassifierTest.php tests/Feature/VariantUsageAuditServiceTest.php`

Expected: PASS; 10/100/401 query assertions prove ProductVariant chunk count does not repeat full ActivityLog scans.

- [ ] **Step 5: Record task completion without committing**

Record the exact passing command and observed 10/100/401 counts in the execution ledger. Do not run `git add` or `git commit`.

---

### Task 2: Exact Chunk-Batched Usage and Reference Auditing

**Files:**
- Modify: `app/Services/VariantUsageAuditService.php`
- Modify: `app/Services/VariantReferenceDiscoveryService.php`
- Modify: `app/Services/SyntheticDefaultVariantAuditService.php`
- Modify: `tests/Feature/VariantUsageAuditServiceTest.php`
- Modify: `tests/Feature/VariantReferenceDiscoveryServiceTest.php`
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

**Interfaces:**
- Consumes: Task 1 complete global `SyntheticDefaultVariantEvidenceSnapshot`.
- Produces: `VariantUsageAuditService::auditManyWithEvidence(Collection $variants, SyntheticDefaultVariantEvidenceSnapshot $evidence): Collection`, keyed by integer variant ID.
- Produces: cached `VariantReferenceDiscoveryService::discover()` definitions reused for all bulk chunks.
- Produces: the same usage result shape as `audit(ProductVariant)`, including every reference count, blocking reason, and `safe_to_remove`.

- [ ] **Step 1: Add failing bulk/single parity tests**

Create representative variants covering: two warehouse rows that net to zero but contain nonzero rows; canonical reservations; cached stock/reserved and product reserved; external mapping; ActivityLog evidence; purchase, invoice, preinvoice, reservation, stock movement, warehouse transfer/count/location/review/inbound, returns, price history, deactivation, commission, seller-sales, invoice-revision, and an unknown discovered reference. Run `audit()` for each fixture and `auditManyWithEvidence()` for the same refreshed collection/snapshot, then assert exact equality for:

```php
[
    'warehouse_stock', 'reserved', 'purchase_refs', 'invoice_refs',
    'preinvoice_refs', 'reservation_refs', 'stock_movement_refs',
    'activity_refs', 'reference_counts', 'other_reference_tables',
    'blocking_reasons', 'safe_to_remove',
]
```

Add query-listener tests with multiple candidates asserting each discovered reference table/column is queried once per candidate chunk, not once per candidate. Add an injected/temporary invalid discovered reference whose grouped query throws and assert the bulk audit aborts rather than returning zero.

- [ ] **Step 2: Run parity/scaling tests and verify RED**

Run: `php artisan test tests/Feature/VariantUsageAuditServiceTest.php tests/Feature/VariantReferenceDiscoveryServiceTest.php tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

Expected: FAIL because `auditManyWithEvidence()` does not exist and current audit performs per-candidate warehouse/reservation/reference queries.

- [ ] **Step 3: Implement batch aggregation and shared pure result assembly**

Implement `auditManyWithEvidence()` so it:

1. validates every variant is in the complete snapshot scope;
2. loads warehouse `SUM(quantity)` and a nonzero-row flag grouped by variant ID in bounded `whereIn` queries;
3. groups candidate variant IDs by product ID and calls the existing `ReservationQueryService::quantitiesByVariant($productId, $variantIds)` once per product group, chunking oversized ID lists without redefining reservation scopes;
4. resolves discovered definitions once, then runs one `select column, count(*) ... whereIn ... groupBy column` query per definition for the candidate chunk;
5. builds zero defaults only after each aggregate query returned successfully;
6. feeds the aggregates and model scalar values into one private pure assembler also used by single-record `audit()` after its fresh queries.

Keep `audit()` fresh and authoritative. Do not make it call the global snapshot path. Preserve core-reference grouping, unknown-reference blockers, `other_reference_tables`, blocking-reason order/deduplication, and the individual-nonzero-warehouse-row rule exactly.

- [ ] **Step 4: Integrate bulk usage into the ProductVariant chunk callback**

Classify the loaded 200-row ProductVariant chunk from Task 1's snapshot, collect only synthetic candidates, call `auditManyWithEvidence()` once for that collection, and build rows from the keyed bulk results. Release candidate-specific aggregate maps when the callback returns.

- [ ] **Step 5: Run usage parity/scaling tests and verify GREEN**

Run: `php artisan test tests/Feature/VariantUsageAuditServiceTest.php tests/Feature/VariantReferenceDiscoveryServiceTest.php tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php`

Expected: PASS; bulk equals single-record results, reference queries scale by definitions/chunks, failure propagates, and cleanup still exercises fresh JSON/reference queries.

- [ ] **Step 6: Record task completion without committing**

Record the passing command and observed reference-query formula/counts in the ledger. Do not commit.

---

### Task 3: Progress Events and Summary-Only Command Mode

**Files:**
- Modify: `app/Services/SyntheticDefaultVariantAuditService.php`
- Modify: `app/Console/Commands/AuditSyntheticDefaultVariants.php`
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`
- Modify: `tests/Feature/CanonicalBaseVariantServiceTest.php`

**Interfaces:**
- Consumes: Task 1 global evidence index and Task 2 keyed bulk usage results.
- Produces: `SyntheticDefaultVariantAuditService::rows(?int $productId = null, ?int $variantId = null, ?Closure $progress = null): Collection`, where the callback receives `(string $phase, array{scanned?:int,total?:int,synthetic?:int} $metrics)`, with stable phase/progress events.
- Produces: Artisan option `--summary-only`.
- Preserves: `summary(Collection $rows): array<string,int>` and exact `CanonicalBaseVariantService::missingBaseProductIds()` semantics.

- [ ] **Step 1: Add failing progress, summary-only, and scope tests**

Add command tests asserting output contains, in order, `Scanning activity evidence...`, `Classifying variants...`, `Auditing usage...`, and `Building summary...`, plus periodic `variants scanned / total` and cumulative synthetic-candidate values without one line per variant.

Run normal and `--summary-only` against the same fixture and assert every summary `key=value` is identical. Assert summary-only output does not contain the CSV/table header or candidate row values, still triggers usage/reference queries, and performs no INSERT/UPDATE/DELETE/DDL statements. Repeat with `--product-id` and `--variant-id`, including a nonexistent ID, to prove scope identity/evidence/results are not widened.

- [ ] **Step 2: Run command tests and verify RED**

Run: `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/CanonicalBaseVariantServiceTest.php`

Expected: FAIL because progress callbacks and `--summary-only` are absent.

- [ ] **Step 3: Implement phase/chunk progress**

Add an optional progress callback to the audit orchestration. Emit phase events before evidence, classification, usage, and summary work. Emit one compact progress event per ProductVariant/candidate chunk containing scanned count, total scoped variants, and cumulative synthetic count. The service must remain console-agnostic; the command formats ordinary cPanel-safe lines.

- [ ] **Step 4: Implement `--summary-only` without skipping audit work**

Add `{--summary-only : Run the complete audit and print only summary counters}` to the command signature. Always call the same audit service path and compute the same summary. Conditionalize only the CSV header/table rendering. Preserve completion output and selectors.

- [ ] **Step 5: Verify exact summary semantics and GREEN tests**

Run: `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/CanonicalBaseVariantServiceTest.php tests/Feature/VariantUsageAuditServiceTest.php`

Expected: PASS; modes have identical summaries, progress is visible, scoped modes stay scoped, and `missing_base` parity tests remain green.

- [ ] **Step 6: Record task completion without committing**

Record the passing command in the ledger. Do not commit.

---

### Task 4: Cleanup Isolation and Complete Validation

**Files:**
- Verify unchanged: `app/Services/SyntheticDefaultVariantCleanupService.php`
- Modify only if a failing test requires stronger coverage: `tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php`
- Verify: every changed/new PHP file

**Interfaces:**
- Consumes: all prior task behavior.
- Produces: validation evidence and final report only; no production change.

- [ ] **Step 1: Strengthen cleanup isolation regression if needed**

Ensure the cleanup test spies/listens for fresh single-record ActivityLog JSON and per-record usage/reference queries during both initial and final locked assessments. Assert no global evidence loader/bulk method is invoked and the transaction still locks/reloads before mutation. If existing coverage does not prove both assessments, add the failing assertion first and run it RED before any test-supporting production change.

- [ ] **Step 2: Run focused audit scaling tests**

Run: `php artisan test tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php tests/Feature/SyntheticDefaultVariantClassifierTest.php tests/Feature/VariantUsageAuditServiceTest.php tests/Feature/VariantReferenceDiscoveryServiceTest.php tests/Feature/CanonicalBaseVariantServiceTest.php tests/Feature/SyntheticDefaultVariantCleanupServiceTest.php`

Expected: PASS with reported assertion totals and measured ActivityLog/reference query counts.

- [ ] **Step 3: Run Phase 5, product/purchase, warehouse, and Phase 1-4 reservation matrices**

Use the repository's existing named test-file groups discovered via `rg --files tests` and the prior Phase 5 validation matrix. Run each group separately, preserve full output in the execution workspace, and report pass/skip/assertion totals. Any failure invokes systematic debugging before proceeding.

- [ ] **Step 4: Run full suite and static formatting checks**

Run:

```text
php artisan test
php -l <each changed/new PHP file>
vendor/bin/pint --test
git diff --check
```

Expected: full suite PASS (existing explicit skips allowed), every PHP file reports no syntax errors, Pint PASS, and diff check emits no errors.

- [ ] **Step 5: Run targeted safety/source searches**

Search outside `.claude/` and report evidence that:

- no unrestricted ActivityLog scan exists inside the ProductVariant chunk callback;
- no per-candidate reference COUNT loop remains in bulk audit;
- cleanup still calls fresh `classify()` and `audit()` inside its transaction and final recheck;
- audit paths contain no DB writes, Base Variant creation, StockMovement creation, or `WarehouseStockService::change/set`;
- no migration or DB/server configuration file was added or modified.

- [ ] **Step 6: Obtain one fresh whole-change code review**

Use `superpowers:requesting-code-review` with the complete uncommitted diff, this plan/spec, the Review Focus list, and ledger rulings. Fix Critical/Important findings in one TDD pass; report deferred Minor findings. Do not commit.

- [ ] **Step 7: Capture final state and stop for review**

Run `git diff --stat` and `git status --short --branch`, explicitly confirm `.claude/` was not read or modified, and produce the requested 16-part PASS/CONCERN report. Do not commit, push, deploy, mutate production, or clean unrelated files.
