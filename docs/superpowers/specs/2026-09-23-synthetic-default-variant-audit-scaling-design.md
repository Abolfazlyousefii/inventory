# Synthetic Default Variant Audit Scaling Design

## Purpose

Make `inventory:audit-synthetic-default-variants` production-safe on large `activity_logs` tables without changing Phase 5 classification, usage, cleanup, or deletion semantics. The audit remains read-only and continues processing `ProductVariant` records in bounded chunks.

## Constraints

- Do not access or mutate production.
- Do not deploy, commit, push, add migrations, or change MySQL/PHP configuration.
- Do not read or modify `.claude/`; its existing untracked state is allowed.
- Preserve the command name, selectors, summary keys, and final completion message.
- Do not create Base Variants or write any database record during audit.
- Cleanup must continue using fresh transaction-local classification and usage evidence.

## Root cause

The current full audit performs multiple queries per variant. Every variant queries `activity_logs` for exact `electric_default_color_created` evidence. Every non-proven variant performs an unindexed `JSON_CONTAINS(properties->variant_id, ...)` contrary-evidence lookup. Every candidate classified as synthetic repeats the contrary activity lookup in `VariantUsageAuditService` to compute usage counts.

Candidate usage auditing also repeats schema discovery and reference-count queries. Summary processing then finds a variant and product and calls `CanonicalBaseVariantService::inspect()` once per unique candidate product. That inspection refreshes the product and can repeat canonical variant, product structure, warehouse, conflict, usage, schema, and reference queries.

Consequently, large production audits perform an `activity_logs` JSON scan once or twice per relevant variant. Since no JSON index may be assumed, work grows approximately as audited variants multiplied by activity-log size. The observed MySQL error 2006 is consistent with the connection being lost during this repeated expensive workload; ordinary database connectivity remains healthy.

## Audit-only evidence snapshot

Introduce a dedicated read-only `SyntheticDefaultVariantEvidenceService` and an immutable evidence snapshot value object.

For each `ProductVariant` chunk supplied by `SyntheticDefaultVariantAuditService`:

1. Collect the exact variant IDs and product IDs in that chunk.
2. Query exact creation evidence for the chunk's product IDs, in bounded activity-log ID chunks. Parse `properties` in PHP and accept proof only when all existing requirements match exactly:
   - action is `electric_default_color_created`;
   - subject type is `Product::class`;
   - subject ID equals the variant's product ID;
   - `properties.product_id` is an integer equal to that product ID;
   - `properties.variant_id` is an integer equal to that variant ID.
3. Query non-neutral direct evidence in one bounded query family using `subject_type = ProductVariant::class` and `subject_id IN (chunk variant IDs)`.
4. Scan non-neutral activity logs in bounded activity-log ID chunks and parse `properties.variant_id` in PHP. Retain matches only for the current variant chunk.
5. Merge direct and property-based evidence by activity-log ID per variant so a single row matching both routes counts once.
6. Release the snapshot after the ProductVariant chunk is processed.

The number of activity-log queries therefore depends on ProductVariant chunks and bounded activity-log chunks, never individual variants. No `ActivityLog::all()` or unbounded activity-log `get()` is permitted.

### Completeness and fail-closed behavior

An evidence snapshot is valid only after all required query/chunk scans for its ProductVariant chunk complete successfully. The service constructs and returns the immutable snapshot only after completion.

Any query failure, interrupted scan, non-progressing ID cursor, missing expected chunk state, unsafe required-property decoding, or other inability to prove completeness throws an explicit exception and aborts the audit command. No partial snapshot is returned. An incomplete scan can never be interpreted as absence of proof or contrary evidence.

Malformed individual `properties` payloads keep the existing conservative semantics: they cannot prove synthetic origin. For non-neutral contrary evidence, safely decoded integer `variant_id` values are matched exactly; malformed values are not broadened or coerced. Loader infrastructure failure is distinct from malformed historical payload data and fails the audit explicitly.

## Classification integration

`SyntheticDefaultVariantClassifier::classify(ProductVariant)` remains the single-record implementation. It continues issuing fresh database queries and remains the only classifier path used by cleanup.

Add an explicit audit-only method such as `classifyWithEvidence(ProductVariant, SyntheticDefaultVariantEvidenceSnapshot)`. It shares pure signal evaluation with `classify()` but obtains exact proof and contrary evidence from the complete snapshot. No mutable global classifier state is introduced.

`PROVEN_SYNTHETIC`, `PROBABLE_SYNTHETIC`, and `NOT_SYNTHETIC` requirements remain byte-for-byte equivalent in meaning. In particular, malformed/string/wrong product or variant identifiers never establish proof, and contrary evidence continues preventing probable classification.

## Usage integration

The ordinary `VariantUsageAuditService::audit(ProductVariant)` path remains fresh and unchanged in authority for cleanup.

Add an audit-only entry point that accepts the complete chunk snapshot and uses its exact per-variant non-neutral activity count instead of running another JSON query. All warehouse, reservation, registered reference, unknown reference, external mapping, and cached-value checks retain their existing behavior.

One `ActivityLog` matching both direct subject and `properties.variant_id` routes contributes exactly one activity reference, matching the current SQL `OR` query semantics.

## Schema discovery caching

Cache only the collection of discovered table/column reference definitions in the `VariantReferenceDiscoveryService` instance. The cache lifetime is the resolved service/command process. Never cache per-variant counts or safety results.

Discovery remains fail-closed: unknown conventional/foreign-key references remain reported and block deletion when populated. Schema/query errors continue to propagate rather than producing an empty definition set.

## Summary optimization

Preserve the exact existing `missing_base` definition:

- consider each unique product represented by a reported synthetic row;
- obtain the same result as `CanonicalBaseVariantService::inspect($product)` for the predicate `state !== NOT_SIMPLE && variant === null`;
- count the product only when that predicate is true.

Provide a bounded batch inspection API in `CanonicalBaseVariantService` (or an exact collaborating service) that batch-loads the relevant products and canonical variants, and batch-resolves product structure. It must preserve the ordering of existing semantics: an existing exact canonical variant means `variant !== null` even if other structure metadata would classify the product as non-simple. For products without an exact canonical variant, the same `ProductVariantStructureService` rules determine `NOT_SIMPLE` versus a missing Base Variant.

Carry the exact per-product result from the audit pass into row metadata or summary context so `summary()` performs no `ProductVariant::find()` or full `inspect()` per row/product. The optimization must not create a Base Variant and must not approximate canonical identity.

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
- activity-log query counts for 10 and 100 variants are bounded by chunk behavior rather than growing roughly tenfold;
- schema discovery executes once per service lifetime while reference counts remain per variant;
- summary no longer performs one variant/product/Base Variant inspection lookup per reported row while producing identical counts;
- cleanup tests demonstrate that fresh transaction-local queries remain in use.

After focused tests, run all Phase 5 tests, product/purchase regressions, warehouse regressions, Phase 1-4 reservation regressions, the full suite, PHP lint for every changed/new PHP file, `git diff --check`, and targeted source searches for forbidden JSON N+1, writes, stock movement creation, stock-changing calls, and migrations.

## Completion criteria

The command finishes with `Read-only audit complete; no data was changed.` on a complete successful scan. Evidence-loader incompleteness fails explicitly. Classification and cleanup safety semantics are unchanged. Activity-log scans and summary lookups are bounded, the full validation matrix is reported, and the work remains uncommitted and undeployed for review.
