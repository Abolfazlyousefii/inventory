# Synthetic Default Variant Audit Scaling Design

## Purpose

Make `inventory:audit-synthetic-default-variants` production-safe end-to-end on large `product_variants`, `activity_logs`, and reference tables without changing Phase 5 classification, usage, cleanup, or deletion semantics. The audit remains read-only. ProductVariant models and ActivityLog rows are still processed in bounded chunks, while compact evidence for the complete requested audit scope is built once and reused by every ProductVariant chunk.

## Constraints

- Do not access or mutate production.
- Do not deploy, commit, push, add migrations, or change MySQL/PHP configuration.
- Do not read or modify `.claude/`; its existing untracked state is allowed.
- Preserve the command name, `--product-id` / `--variant-id` selectors, existing default table output, summary keys, and final completion message.
- Add an optional `--summary-only` mode that performs the same complete classification and safety audit but suppresses the row table.
- Do not create Base Variants or write any database record during audit.
- Cleanup must continue using fresh transaction-local classification and usage evidence.

## Root cause and why the previous test missed it

The first scaling hotfix removed per-variant ActivityLog JSON queries, but placed `SyntheticDefaultVariantEvidenceService::load($variants)` inside `SyntheticDefaultVariantAuditService::rows()`'s `chunkById(200)` callback. Creation evidence is product-filtered, but contrary evidence scans every non-neutral ActivityLog row. The full table is therefore streamed once per 200 ProductVariants. With roughly 20,000 variants, this is roughly 100 full ActivityLog scans.

The earlier query-count fixtures used 10 and 100 variants. Both fit in one ProductVariant chunk, so both correctly observed two ActivityLog query executions for that one chunk. They did not cross the 200-row boundary and could not detect that a second and subsequent ProductVariant chunk each started another complete ActivityLog pass. A mandatory regression fixture with at least 401 variants will exercise three ProductVariant chunks and prove the global scan count remains fixed.

After classification, candidate usage auditing remains per-candidate: warehouse aggregate/presence queries, canonical reservation aggregation, and one COUNT query per discovered reference definition. The command also buffers every report row and emits no phase/progress output until `rows()` finishes, making long-running legitimate work appear frozen.

## Single global audit-only evidence index

Before classifying any ProductVariant chunk, collect the requested scope as a compact integer map `variant_id => product_id`. Scope collection selects only identity columns and does not retain Eloquent models globally. Then construct one immutable audit evidence index for the entire command run.

The evidence service performs a small fixed number of ActivityLog query families for the complete scope:

1. Query exact creation evidence for the scoped product IDs and stream it in bounded ActivityLog ID chunks. Parse `properties` in PHP and accept proof only when all existing requirements match exactly:
   - action is `electric_default_color_created`;
   - subject type is `Product::class`;
   - subject ID equals the variant's product ID;
   - `properties.product_id` is an integer equal to that product ID;
   - `properties.variant_id` is an integer equal to that variant ID.
2. Scan non-neutral ActivityLog rows once in bounded ActivityLog ID chunks. For each streamed row, match both a direct `ProductVariant` subject and a safely decoded integer `properties.variant_id` against the global scope map. Deduplicate matching variant IDs within that row so one log referencing the same variant through both routes contributes one count.
3. Retain only compact results: the scope map, one exact creation log ID per proven variant, and one contrary-evidence count per scoped variant. Do not retain ActivityLog models or raw properties after each ActivityLog chunk is consumed.

The number of full ActivityLog scans is constant for the entire audit command and independent of the number of ProductVariant chunks. ProductVariant chunks obtain scoped views/slices from the same complete index. No `ActivityLog::all()` or unbounded ActivityLog `get()` is permitted.

Approximate retained-memory complexity is `O(number of audited variants)`: integer scope identity plus compact evidence scalars. ActivityLog memory is bounded by the ActivityLog stream chunk size.

### Completeness and fail-closed behavior

The global evidence index is valid only after all required query/chunk scans for the complete requested scope finish successfully. The service constructs and returns the immutable index only after completion.

Any query failure, interrupted scan, non-progressing ID cursor, missing expected chunk state, unsafe required-property decoding, or other inability to prove completeness throws an explicit exception and aborts the audit command. No partial snapshot is returned. An incomplete scan can never be interpreted as absence of proof or contrary evidence.

Malformed individual `properties` payloads keep the existing conservative semantics: they cannot prove synthetic origin. For non-neutral contrary evidence, safely decoded integer `variant_id` values are matched exactly; malformed values are not broadened or coerced. Loader infrastructure failure is distinct from malformed historical payload data and fails the audit explicitly.

## Classification integration

`SyntheticDefaultVariantClassifier::classify(ProductVariant)` remains the single-record implementation. It continues issuing fresh database queries and remains the only classifier path used by cleanup.

Add an explicit audit-only method such as `classifyWithEvidence(ProductVariant, SyntheticDefaultVariantEvidenceSnapshot)`. It shares pure signal evaluation with `classify()` but obtains exact proof and contrary evidence from the complete snapshot. No mutable global classifier state is introduced.

`PROVEN_SYNTHETIC`, `PROBABLE_SYNTHETIC`, and `NOT_SYNTHETIC` requirements remain byte-for-byte equivalent in meaning. In particular, malformed/string/wrong product or variant identifiers never establish proof, and contrary evidence continues preventing probable classification.

## Batched usage integration

The ordinary `VariantUsageAuditService::audit(ProductVariant)` path remains fresh and unchanged in authority for cleanup.

Add an audit-only bulk entry point such as `auditManyWithEvidence(Collection $variants, SyntheticDefaultVariantEvidenceSnapshot $evidence)`. The ordinary `audit(ProductVariant)` implementation remains the cleanup authority and is not redirected through cached bulk data.

For each synthetic candidate chunk, the bulk path:

- aggregates warehouse quantity and whether any individual nonzero warehouse row exists, grouped by variant ID;
- groups candidate IDs by product ID and calls `ReservationQueryService::quantitiesByVariant()` in bounded product groups, preserving its canonical active-reservation semantics rather than duplicating them;
- calls `VariantReferenceDiscoveryService::discover()` once/cached once, then issues one `WHERE IN (...) GROUP BY variant_id` query per discovered table/column definition per candidate chunk;
- reads cached stock, cached reserved, product cached reserved, and external mapping from the already loaded candidate/product models;
- reads ActivityLog counts only from the complete global audit evidence index.

Every batch query must finish successfully. Query/schema failures propagate and abort the audit; a missing result for an in-scope variant is interpreted as zero only after the aggregate query itself completed successfully.

One `ActivityLog` matching both direct subject and `properties.variant_id` routes contributes exactly one activity reference, matching the current SQL `OR` query semantics. The bulk result must have exact parity with single-record `audit()` for counts, ordered/normalized blocking reasons, `other_reference_tables`, and `safe_to_remove`.

## Schema discovery caching

Cache only the collection of discovered table/column reference definitions in the `VariantReferenceDiscoveryService` instance. The cache lifetime is the resolved service/command process. Never cache single-record cleanup counts or safety results. Bulk audit reference aggregates live only for the current candidate chunk and are released before the next chunk.

Discovery remains fail-closed: unknown conventional/foreign-key references remain reported and block deletion when populated. Schema/query errors continue to propagate rather than producing an empty definition set.

## Summary optimization

Preserve the exact existing `missing_base` definition:

- consider each unique product represented by a reported synthetic row;
- obtain the same result as `CanonicalBaseVariantService::inspect($product)` for the predicate `state !== NOT_SIMPLE && variant === null`;
- count the product only when that predicate is true.

Provide a bounded batch inspection API in `CanonicalBaseVariantService` (or an exact collaborating service) that batch-loads the relevant products and canonical variants, and batch-resolves product structure. It must preserve the ordering of existing semantics: an existing exact canonical variant means `variant !== null` even if other structure metadata would classify the product as non-simple. For products without an exact canonical variant, the same `ProductVariantStructureService` rules determine `NOT_SIMPLE` versus a missing Base Variant.

Carry the exact per-product result from the audit pass into row metadata or summary context so `summary()` performs no `ProductVariant::find()` or full `inspect()` per row/product. The optimization must not create a Base Variant and must not approximate canonical identity.

## Streaming orchestration, progress, and summary-only mode

The audit orchestration has four visible phases suitable for cPanel terminals:

1. `Scanning activity evidence...`
2. `Classifying variants...`
3. `Auditing usage...`
4. `Building summary...`

Progress is emitted periodically, at most once per ProductVariant/candidate chunk rather than once per row, and includes `variants scanned / total` plus the cumulative synthetic-candidate count. Output uses ordinary lines or a terminal-compatible progress bar and does not require an interactive TTY.

Normal mode remains compatible: it renders the existing row headers/table followed by identical summary counters. `--summary-only` executes the same complete evidence scan, classification, bulk usage checks, and exact summary computation, but does not render the row table. It may retain only the compact fields needed for summary computation; it must not skip safety work. Normal and summary-only modes must produce identical summary values and neither mode may write to the database.

Both modes honor `--product-id` and `--variant-id` when building the identity map, evidence index, candidate batches, reference aggregates, progress total, and summary.

## Cleanup isolation

No evidence snapshot is passed to `SyntheticDefaultVariantCleanupService`.

Cleanup continues to:

1. start its transaction;
2. lock and reload the candidate;
3. run fresh single-record classification;
4. run fresh usage/reference auditing;
5. reject non-synthetic or unauthorized probable candidates;
6. repeat the complete assessment immediately before mutation;
7. delete only zero-quantity warehouse rows;
8. delete the variant last;
9. create no stock movement, call no stock-changing service, and migrate no history.

`--all-safe` remains limited to `PROVEN_SYNTHETIC + SAFE_TO_REMOVE`. Probable candidates remain explicit-ID-only and still require every safety check.

## Testing

Tests must be written and observed failing before production changes. Coverage includes:

- exact proof succeeds;
- wrong product, wrong variant, string IDs, and malformed properties do not prove origin;
- direct and property-only contrary evidence block probable classification;
- neutral `created` rows do not block probable classification;
- a row referencing the same variant by subject and property counts once;
- an incomplete/failing evidence scan aborts explicitly and cannot yield a snapshot;
- audit performs no database writes;
- activity-log query counts for 10, 100, and at least 401 variants remain fixed/bounded by ActivityLog chunk volume, never ProductVariant chunk count;
- the mandatory >200 regression proves multiple ProductVariant chunks do not repeat the full ActivityLog scan;
- schema discovery executes once per service lifetime and reference queries scale by candidate chunks times discovered definitions, not candidate count;
- representative single-record and bulk usage results have identical counts, blocking reasons, other-reference reporting, and `safe_to_remove`;
- progress phase/chunk output is emitted without one line per variant;
- `--summary-only` suppresses the row table while returning the same summary and performing no writes;
- summary no longer performs one variant/product/Base Variant inspection lookup per reported row while producing identical counts;
- cleanup tests demonstrate that fresh transaction-local queries remain in use.

After focused tests, run all Phase 5 tests, product/purchase regressions, warehouse regressions, Phase 1-4 reservation regressions, the full suite, PHP lint for every changed/new PHP file, `git diff --check`, and targeted source searches for forbidden JSON N+1, writes, stock movement creation, stock-changing calls, and migrations.

## Completion criteria

The command finishes with `Read-only audit complete; no data was changed.` on a complete successful scan. Evidence-loader or batched-reference incompleteness fails explicitly. Classification, every `safe_to_remove` blocker, exact `missing_base`, and cleanup safety semantics are unchanged. Full ActivityLog scans are constant per command; usage/reference work is chunk-batched; progress is visible; summary-only mode is complete and read-only; the full validation matrix is reported; and the work remains uncommitted and undeployed for review.
