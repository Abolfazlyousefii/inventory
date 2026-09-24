# Product Variant Integrity Phase 5 Design

## Purpose

Phase 5 permanently retires automatic black/white variant creation for electrical products, prevents ordinary product editing from changing sales status, defines safe canonical Base Variant semantics, and introduces auditable fail-closed tooling for identifying and removing only unused synthetic variants. It preserves every business-history row, never guesses prices, never migrates or merges stock, and leaves the Phase 1–4 reservation lifecycle unchanged.

## Baseline and non-negotiable constraints

- Work is based on local `main` at `6b55271d`.
- The pre-existing untracked `.claude/` directory is an allowed exception and must not be read, modified, staged, ignored, deleted, renamed, moved, or committed.
- Development and tests must not access or mutate production.
- No implementation commit, push, deployment, or production command execution is authorized.
- `warehouse_stocks.quantity` remains physical-stock authority.
- `products.stock` and `product_variants.stock` remain projections.
- Canonical reservation queries remain reserved-stock authority; cached `reserved` fields cannot authorize deletion.
- Phase 5 never moves or merges physical stock and never reassigns historical rows to another variant.
- Missing sell or buy prices are reported, never inferred from unrelated products or historical guesses.
- Normal product create/edit/update cannot mutate `product_variants.is_active`, `product_variants.sales_enabled`, or `products.is_sellable` through structure reconciliation.
- Explicit product-sales-status workflows retain authority to change sales status.

## Task 0 findings and current root causes

### Synthetic black/white creation

There is one implementation method with two production call sites:

1. `ProductController::store()` calls `DefaultProductDesignService::ensureElectricDefaultColors()` after creating configured variants.
2. `ProductController::update()` calls the same method after variant synchronization and site-ID updates.

`DefaultProductDesignService` recognizes the `برقیجات` category by name or `barghijat` slug across its ancestry, creates missing `مشکی` and `سفید` variants, creates zero-valued central warehouse rows, logs `electric_default_color_created`, and recalculates product stock/price. Repeated calls are intended to be idempotent but keep the implicit category behavior alive.

Phase 5 removes both call sites. The service remains available for historical evidence parsing until all references are retired; it must not be invoked automatically by product creation or editing.

### Automatic structural deactivation

`ProductController::update()` calls `ProductVariantStructureService::deactivateInvalidVariants($product)` after synchronizing model/design metadata. That method identifies variants outside the current structure and directly writes `is_active=false`, including historical variants that became structurally invalid only because metadata changed.

Phase 5 removes this call from normal editing. `audit()` and read-only warnings may remain. Explicit `ProductSalesStatusService`, `ProductSalesStatusBulkService`, product-deactivation documents, and confirmed repair commands remain separate business workflows and are not replaced by product editing.

### Product summary price risk

`DefaultProductDesignService::recalculateProductSummary()` takes the minimum sell price across active variants without excluding zero, so a generated zero-price variant can force a healthy product summary to zero. `ProductVariantStructureService::recalculateProductSummary()` already filters `sell_price > 0`, but limits input through current structural validity and writes zero if none qualifies.

Phase 5 establishes one summary contract: only usable positive variant prices participate; zero/inactive placeholder or synthetic rows cannot zero a healthy summary; if no valid positive price exists the projection remains/reports zero without guessing.

### Purchase routing

Purchase requests carry a variant ID and backend validation verifies it against structural constraints. There is no canonical Base Variant resolver, and current structure rules can hide historical variants. Phase 5 makes resolution explicit: a request either names a real eligible variant or deliberately resolves a genuinely simple product to its deterministic Base Variant. Missing safe Base Variant is a validation error. No query ordering or color name may select a fallback.

### Other status and price writers

Explicit sales-status services and product-deactivation workflows write `is_sellable`, `is_active`, or `sales_enabled` by user/business action. Audit/repair commands may also write these fields only in explicitly confirmed repair modes. Purchase and price-change flows can update the selected variant price and recalculate product summaries. These paths require regression coverage but are not implicit structure reconciliation and therefore remain in scope only where Phase 5 contracts affect them.

## Variant state table

| Variant/product class | May purchase? | May sell? | May appear in UI? | May delete? | May auto-create? | May auto-deactivate? | Price source | Stock ownership | Migration allowed? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Genuine multi-variant | Yes, only by explicit variant ID | According to explicit product/variant sales flags | Yes | No if any business evidence; ordinary deletion is outside Phase 5 | Only through explicit user/import configuration | No | Its explicit positive `sell_price`; product summary is projection | Its own `warehouse_stocks` rows | No |
| Canonical Base Variant | Yes, as the deterministic target for a genuinely simple product | According to explicit sales flags | Yes | Only under ordinary future rules; not synthetic cleanup | Yes, only when safety preflight proves no migration/history reassignment | No | Deterministic positive product price at creation; otherwise missing and reported | Its own variant warehouse rows; no invented quantity | No |
| Proven synthetic unused | No new routing | No implicit sales authority | Audit/admin UI; normal catalog visibility follows existing flags | Yes only when `SAFE_TO_REMOVE`, locked, reloaded, and revalidated | No | Ignored as placeholder if non-positive | Must total zero everywhere | No |
| Proven synthetic protected | Only if explicitly selected and currently eligible under existing business rules | According to preserved explicit flags | Yes, with protected evidence | Never in Phase 5 | No | Preserved historical value | Preserved exactly | No |
| Probable synthetic | No automatic routing | According to preserved explicit flags | Yes, marked review-only | Never through `--all-safe`; explicit reviewed ID only if full audit is safe | No | Preserved; non-positive value ignored by summary | Preserved unless explicit reviewed deletion proves zero/no history | No |
| Structurally invalid historical | Not as an implicit fallback; explicit existing-document reads remain valid | According to explicit flags, not structure cleanup | Yes in audit/history; admin UI may warn | Never merely because structurally invalid | No | Preserved; summary uses only usable positive current variants | Preserved exactly | No |
| Inactive explicitly by business action | No new purchase/sale unless explicit workflow reactivates it | No | Yes in admin/history | No if any evidence; synthetic rules do not override history | No | Preserved; excluded from active summary | Preserved exactly | No |
| Blocked legacy/simple product | No fallback; purchase fails with explicit Base Variant review message | Existing behavior remains; no silent status mutation | Yes with `blocked_for_base_variant_review` | No | No until reviewed | No | Existing projection preserved/reported; no guess | Ambiguous product/variant/history ownership | No |

## Canonical Base Variant contract

A canonical Base Variant represents a product with no models, designs, or colors. Its invariant is:

- `product_id` is the owning product.
- `model_list_id` is null.
- `variety_code` is exactly `0000`.
- `variant_code` follows the existing product-code plus `000` model plus `00` design convention and is globally unique.
- `variant_name` clearly identifies the product’s base/main variant.
- `is_active=true`.
- `sales_enabled` follows the product’s explicit sales setting at creation time; later changes occur only through sales-status workflows.
- `sell_price` may copy the product’s positive price only at creation and only because that value is deterministic for the same product. If it is missing/non-positive, no price is invented.
- `stock=0` and `reserved=0`; no physical stock is invented.

Creation is idempotent and permitted only after a read-only preflight proves all of the following:

- the product is genuinely simple under its explicit metadata;
- no canonical Base Variant already exists;
- no product-level warehouse row owns non-zero quantity;
- no synthetic or other variant owns stock that would need moving;
- no purchase, invoice, preinvoice, reservation, movement, transfer, count, inbound/outbound, return, price-change, site mapping, or other business history would need reassignment;
- no ambiguous legacy ownership exists.

Otherwise the product is classified `blocked_for_base_variant_review`. Phase 5 reports it and does not create, move, merge, or relink anything.

## Synthetic evidence classification

`SyntheticDefaultVariantClassifier` is read-only and returns a class, reasons, and structured evidence.

Evidence priority:

1. `PROVEN_SYNTHETIC`: an `activity_logs` entry with action `electric_default_color_created` whose properties match both `product_id` and `variant_id`. This is the strongest proof.
2. `PROBABLE_SYNTHETIC`: all structural signals match—electrical category lineage, null model, normalized exact black/white default naming, and the precise legacy code pattern—and there is no contrary/manual-creation evidence.
3. `NOT_SYNTHETIC`: evidence is absent, contradictory, or incomplete.

Category/name/code patterns alone never authorize bulk deletion. The classifier does not inspect usage to decide synthetic origin; origin evidence and deletion safety remain separate concerns.

## Variant usage and reference audit

`VariantUsageAuditService` returns quantitative evidence, discovered reference tables, blocking reasons, and `safe_to_remove`.

Safety requires all of these to be zero/absent:

- every `warehouse_stocks.quantity` and total across all warehouses;
- cached variant stock and cached product/variant reserved values;
- canonical reservation quantity and all active or historical reservation references;
- purchase, invoice, preinvoice, stock movement, warehouse transfer, stock count, warehouse location stock/movement, inbound/outbound, sales return, seller document, commission, price-change, deactivation, collection revision, site/external mapping, and audit/business references;
- `variety_id` or any other external mapping;
- any discovered but unrecognized table/column referencing `product_variants.id`.

The service combines a maintained semantic reference registry with schema metadata discovery. Database-specific metadata adapters may discover foreign keys and conventionally named `variant_id`, `product_variant_id`, or `created_variant_id` columns. Unknown references fail closed into review. Metadata narrows and reports references; it does not replace business-aware canonical checks.

An existing zero-quantity `warehouse_stocks` row is not business history by itself and may be deleted immediately before the variant only when every other safety check passes and the row is required to satisfy foreign-key deletion. Non-zero rows are immutable in Phase 5.

## Audit commands

`inventory:audit-synthetic-default-variants` is read-only. It reports the required row columns and summary counts for proven, probable, safe, history-protected, stock-protected, reservation-protected, other-reference-protected, missing Base Variant, zero-price risk, unexplained inactive variants, and sellable zero-price variants with positive physical stock. A database write guard is enabled where compatible with existing audit-command infrastructure.

The audit output includes:

`product_id`, `product_name`, `variant_id`, `variant_name`, `variant_code`, `synthetic_class`, `synthetic_evidence`, `warehouse_stock`, `reserved`, `purchase_refs`, `invoice_refs`, `preinvoice_refs`, `reservation_refs`, `stock_movement_refs`, `other_reference_tables`, `site_mapping`, `safe_to_remove`, and `blocking_reasons`.

No audit mode repairs data.

## Cleanup command

`inventory:cleanup-synthetic-default-variants` is dry-run by default and requires exactly one selector:

- `--ids=` for explicitly reviewed IDs; proven or probable rows may be considered, but every row must still be `SAFE_TO_REMOVE` at execution time.
- `--all-safe` for automatic selection; only `PROVEN_SYNTHETIC AND SAFE_TO_REMOVE` rows qualify.

Mutation additionally requires both `--apply --confirm`. For every candidate, the cleanup service:

1. starts a transaction;
2. locks and reloads the variant;
3. reloads classifier and usage-audit dependencies;
4. recomputes synthetic evidence and usage;
5. rejects probable rows unless the ID was explicit;
6. rejects anything not still safe;
7. deletes only zero-quantity variant-owned warehouse rows when necessary;
8. deletes the variant without touching business-history tables;
9. recalculates the owning product’s stock/reserved/price projection safely without changing `is_sellable`;
10. reports whether the product lacks a safe Base Variant, without creating one as a cleanup side effect.

The command never calls a physical stock-change API, changes quantity, writes a stock movement, repairs projections speculatively, or deletes referenced history. Repeated execution is idempotent.

## Product editing and sales-status isolation

Product create/edit/update may create or update variants explicitly described by the submitted models/design configuration, but it cannot:

- create black/white variants based on category;
- deactivate variants because metadata changed;
- toggle `sales_enabled` or `is_sellable` through reconciliation;
- remove historical variants merely because they are outside the new structure.

Edits to name, category, image, price, models/design metadata, and unrelated fields preserve all three sales-status fields byte-for-byte unless the request is handled by an explicit sales-status workflow.

## Price summary contract

`products.price` is a projection. The shared recalculation service:

- considers only current usable variants appropriate to the existing product structure;
- excludes inactive, unusable placeholder, and non-positive-price rows;
- cannot let a synthetic zero-price variant force a positive summary to zero;
- never derives a value from another product or historical unrelated row;
- returns/writes zero only when no valid positive price exists, making the missing-price state auditable;
- does not change `products.is_sellable`.

Deleting a safe unused synthetic variant cannot change a valid product price. Product and variant stock/reserved projections remain derived from authoritative warehouse/reservation data under existing services; this phase does not alter physical quantities.

## Purchase safety

Purchase create and update resolve each item through one explicit rule:

1. A supplied variant ID must belong to the product and satisfy purchase eligibility; or
2. For a genuinely simple product, a missing variant ID may resolve only to the one canonical Base Variant.

If no safe canonical Base Variant exists, validation fails with an explicit review message. Multi-variant products always require an explicit variant ID. Resolution never selects the first, lowest-ID, first-active, black, white, or arbitrary structurally valid variant.

Purchase stock changes affect only the selected variant’s `warehouse_stocks` row. Purchase price changes affect only the selected variant. Other variants remain byte-for-byte unchanged.

## Testing and execution gates

Implementation order is mandatory:

1. Task 0 contract tests and call-path inventory.
2. Stop future black/white creation.
3. Stop automatic deactivation.
4. Harden price summaries.
5. Add synthetic evidence classifier.
6. Add usage/reference audit.
7. Add read-only production audit command.
8. Add canonical Base Variant service.
9. Harden purchase routing.
10. Add cleanup command only after classifier/audit tests pass.
11. Run complete regressions and static validation.

Focused tests cover product create/edit, structure services, purchases, sales status, price integrity, warehouse stock, metadata reference discovery, synthetic cleanup neutrality, and Phase 1–4 reservation behavior. Final validation runs all product/purchase suites, all reservation suites, the full suite, PHP syntax checks, and `git diff --check`.

No production audit or cleanup command is run during development. The final implementation report must begin with exact root causes, creation/deactivation path counts, classifier design, and test evidence, then stop for review.
