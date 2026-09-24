# Synthetic Default Variant Audit Scaling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Phase 5 synthetic-default-variant audit scale without per-variant activity-log JSON scans while preserving exact classification, usage, summary, and cleanup safety semantics.

**Architecture:** Each ProductVariant chunk receives a complete immutable audit-only evidence snapshot built from bounded ActivityLog ID scans and retaining only IDs relevant to that variant chunk. Bulk classification and usage consume the snapshot explicitly; cleanup continues using the unchanged fresh single-record database paths. Summary uses an exact batched canonical-base predicate, and schema metadata is cached only in memory for the service lifetime.

**Tech Stack:** PHP 8.x, Laravel/Eloquent, Pest, SQLite test database, MySQL-compatible production queries.

**Spec:** `docs/superpowers/specs/2026-09-23-synthetic-default-variant-audit-scaling-design.md`

## Global Constraints

- Do not access or mutate production.
- Do not deploy, commit, push, add migrations, or change MySQL/PHP configuration.
- Do not read or modify `.claude/`.
- The bulk evidence snapshot is read-only and audit-only.
- Any incomplete evidence scan throws and aborts; partial evidence is never returned.
- Cleanup retains fresh locked transaction-local classification and usage checks.
- Preserve exact PROVEN, PROBABLE, NOT_SYNTHETIC, activity-count, and `missing_base` semantics.
- Keep command options, summary keys, and `Read-only audit complete; no data was changed.` unchanged.

## Review Focus

- Activity rows with numeric strings or malformed JSON must never establish exact proof or contrary evidence through coercion.
- An ActivityLog matching both direct-subject and property-reference routes must count once.
- Empty ActivityLog tables and ID gaps must complete normally; a non-progressing/aborted scan must fail explicitly.
- Existing canonical variants must exclude a product from `missing_base` before non-simple structure evaluation, matching `inspect()` ordering.
- Container lifetimes must not let schema metadata or audit evidence leak across unrelated operations; only invariant definitions may be cached.

---

### Task 1: Pin audit-only evidence semantics and bounded query behavior

**Files:**
- Create: `app/Services/SyntheticDefaultVariantEvidenceSnapshot.php`
- Create: `app/Services/SyntheticDefaultVariantEvidenceService.php`
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

**Interfaces:**
- Produces: `SyntheticDefaultVariantEvidenceService::load(Collection $variants): SyntheticDefaultVariantEvidenceSnapshot`
- Produces: snapshot methods `creationLogId(int $productId, int $variantId): ?int`, `hasContraryEvidence(int $variantId): bool`, and `activityReferenceCount(int $variantId): int`
- Snapshot construction remains internal to the loader so callers cannot mark an incomplete scan complete.

- [ ] Add failing tests that create exact, wrong-product, wrong-variant, malformed, direct contrary, property-only contrary, neutral-created, and dual-route activity rows, then assert the snapshot's exact IDs/counts and one-count deduplication.
- [ ] Add a failing query-count test comparing 10 and 100 variants in one audit chunk and filtering only SQL against `activity_logs`; assert small fixed growth rather than approximately 10x.
- [ ] Add a failing completeness test using an injectable/overridable chunk hook or loader seam that aborts mid-scan and assert `RuntimeException` escapes without a snapshot/audit result.
- [ ] Run `php artisan test --filter=AuditSyntheticDefaultVariantsCommandTest` and verify failures are caused by the missing loader/snapshot behavior.
- [ ] Implement the immutable snapshot and bounded loader. Select only required ActivityLog columns; iterate via `chunkById`; retain only current chunk variant/product matches; track each variant's matching activity-log IDs as a set before converting to counts; construct the snapshot only after every scan completes.
- [ ] Treat database/query/cursor failures as exceptions. Validate strictly integer property identifiers. Permit malformed historical properties to remain non-evidence exactly as the classifier currently does.
- [ ] Re-run the focused test and make it green.

### Task 2: Add explicit bulk classifier and usage entry points

**Files:**
- Modify: `app/Services/SyntheticDefaultVariantClassifier.php`
- Modify: `app/Services/VariantUsageAuditService.php`
- Modify: `tests/Feature/SyntheticDefaultVariantClassifierTest.php`
- Modify: `tests/Feature/VariantUsageAuditServiceTest.php`

**Interfaces:**
- Produces: `SyntheticDefaultVariantClassifier::classifyWithEvidence(ProductVariant $variant, SyntheticDefaultVariantEvidenceSnapshot $evidence): array`
- Produces: `VariantUsageAuditService::auditWithEvidence(ProductVariant $variant, SyntheticDefaultVariantEvidenceSnapshot $evidence): array`
- Preserves: existing `classify()` and `audit()` as fresh database paths.

- [ ] Add failing classifier tests comparing single-record and snapshot results for exact proof, wrong IDs, malformed properties, all conservative signals, direct contrary, property contrary, and neutral-created rows.
- [ ] Add a failing usage test proving dual-route evidence has `activity_refs === 1` and still blocks removal.
- [ ] Add a cleanup regression assertion/listener proving cleanup invokes fresh ActivityLog queries rather than snapshot methods.
- [ ] Run the three focused test classes and verify expected failures.
- [ ] Refactor only pure classification signal assembly into a shared private method. Have `classify()` obtain fresh evidence exactly as before and `classifyWithEvidence()` read the explicit complete snapshot.
- [ ] Refactor usage calculation so `audit()` retains its existing fresh ActivityLog `OR` query and `auditWithEvidence()` substitutes only the exact snapshot count. Share all remaining safety calculations.
- [ ] Re-run focused tests and make them green.

### Task 3: Cache schema definitions without caching safety results

**Files:**
- Modify: `app/Services/VariantReferenceDiscoveryService.php`
- Modify: `tests/Feature/VariantReferenceDiscoveryServiceTest.php`

**Interfaces:**
- Preserves: `discover(): Collection`
- Adds: private nullable cached reference-definition collection scoped to the service instance.

- [ ] Add a failing test/listener that calls `discover()` twice on one instance and asserts schema metadata SQL occurs only on the first call while results are identical.
- [ ] Add or retain a test proving a discovered unknown reference is still returned and usage counts remain fresh after rows change.
- [ ] Run `php artisan test --filter=VariantReferenceDiscoveryServiceTest` and verify the cache-count test fails.
- [ ] Cache only the successful final definitions collection. Do not assign the cache before discovery and mapping complete, so exceptions cannot cache partial/empty metadata.
- [ ] Re-run discovery and usage tests and make them green.

### Task 4: Preserve exact `missing_base` semantics with batched inspection

**Files:**
- Modify: `app/Services/CanonicalBaseVariantService.php`
- Modify: `app/Services/ProductVariantStructureService.php` only if an exact batch structure helper is required
- Modify: `tests/Feature/CanonicalBaseVariantServiceTest.php`
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

**Interfaces:**
- Produces: `CanonicalBaseVariantService::missingBaseProductIds(Collection $productIds): Collection`
- Exact predicate: IDs for products where individual `inspect()` would return `state !== NOT_SIMPLE` and `variant === null`.

- [ ] Add table-driven parity tests covering simple/no-base, simple/active-base, simple/inactive-base, models, designs, colors, empty metadata inferred from variants, conflicting base code, and ambiguous warehouse/history blockers.
- [ ] Add a query-listener test with many reported rows/products proving summary lookup queries are bounded rather than one `find()`/`inspect()` sequence per product.
- [ ] Run canonical and audit tests and verify missing batch API/query-bound failures.
- [ ] Implement bounded product-ID chunks that batch-load products and exact canonical variants. Evaluate canonical existence first. For products without canonical variants, batch-resolve structure using the exact same metadata/fallback rules as `ProductVariantStructureService`; return only exact missing-base IDs.
- [ ] Do not run usage/reference blocker checks because the existing summary predicate counts every simple product lacking a canonical variant regardless of whether `inspect()` would be AVAILABLE or BLOCKED_FOR_REVIEW; prove this equivalence in parity tests.
- [ ] Re-run canonical and audit tests and make them green.

### Task 5: Integrate per-variant-chunk snapshots into the full audit

**Files:**
- Modify: `app/Services/SyntheticDefaultVariantAuditService.php`
- Modify: `app/Console/Commands/AuditSyntheticDefaultVariants.php` only if explicit loader failure context is needed without changing successful output
- Modify: `tests/Feature/AuditSyntheticDefaultVariantsCommandTest.php`

**Interfaces:**
- `rows()` continues returning the existing collection/row shape and selectors.
- Each ProductVariant chunk calls `load($variants)` exactly once and releases the snapshot after the callback.
- Summary consumes carried exact `_missing_base` state or one batched service result.

- [ ] Add/complete failing integration tests for read-only behavior, selectors, exact classification rows, loader failure propagation, bounded ActivityLog query counts, and bounded summary queries.
- [ ] Run the audit command test and capture the expected red result.
- [ ] Inject evidence and canonical-base services. Within each existing `chunkById(200)`, load one complete snapshot, call bulk classifier/usage methods, and discard it at callback exit.
- [ ] Replace summary's `ProductVariant::find()` plus `inspect()` loop with the exact batch API while retaining every existing summary name/value.
- [ ] Confirm successful output still ends with the exact completion message and failures never print a successful completion.
- [ ] Re-run audit, classifier, usage, canonical, and cleanup focused tests and make them green.

### Task 6: Focused and Phase 5 verification

**Files:** No production edits expected; fix regressions test-first if found.

- [ ] Run each requested focused filter: classifier, audit command, cleanup service, cleanup command, usage audit, reference discovery, and canonical base service.
- [ ] Identify all Phase 5 test files from commit `6ebd3e35` and run them together.
- [ ] Record pass/fail counts and exact activity-log query comparison for 10 versus 100 variants.

### Task 7: Broader regression and static verification

**Files:** No production edits expected; fix regressions test-first if found.

- [ ] Run product and purchase regression suites.
- [ ] Run warehouse regression suites.
- [ ] Run Phase 1-4 reservation regression suites.
- [ ] Run the complete test suite and report every failure, including unrelated/pre-existing failures.
- [ ] PHP-lint every changed/new PHP file.
- [ ] Run `git diff --check`.
- [ ] Search for per-variant `orWhereJsonContains` usage in the full audit path, audit DB writes, new `WarehouseStockService::change/set`, new `StockMovement` creation, and new migration files.
- [ ] Inspect `git diff --stat` and `git status --short --branch`; verify `.claude/` remains only its original untracked entry and was not read or changed.

### Task 8: Completion report

**Files:** No changes.

- [ ] Report root cause, exact files changed, old query pattern, new bounded strategy, ActivityLog query-count comparison, summary query improvement, cleanup isolation/safety, focused results, Phase 1-4 results, full suite result, diff stat, status, `.claude/` confirmation, and PASS/CONCERN verdict.
- [ ] Stop for review without committing, pushing, deploying, or running any production command.
