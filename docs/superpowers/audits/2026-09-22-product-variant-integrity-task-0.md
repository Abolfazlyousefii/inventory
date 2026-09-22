# Phase 5 Task 0 — Product Variant Integrity Call-Path Audit

## Authorities

- Physical quantity: `warehouse_stocks.quantity`, accessed through `WarehouseStockService` for mutations.
- Reserved quantity: canonical rows through `ReservationQueryService`; `products.reserved` and `product_variants.reserved` are projections only.
- Product price: projection derived from the canonical product-summary rule introduced in Phase 5 Task 3.
- Sales status: explicit product-sales-status/deactivation workflows only.

## Automatic black/white creation paths

One implementation has two normal product-flow callers:

1. `ProductController::store()` calls `DefaultProductDesignService::ensureElectricDefaultColors()` after explicit variant creation.
2. `ProductController::update()` calls it after variant synchronization.

The service recognizes `برقیجات`/`barghijat` ancestry, creates `مشکی` and `سفید`, creates zero central warehouse rows, logs `electric_default_color_created`, and recalculates product summary values.

## Automatic structural deactivation paths

One normal-edit path exists: `ProductController::update()` calls `ProductVariantStructureService::deactivateInvalidVariants()`, which writes `product_variants.is_active=false` for rows outside submitted model/design metadata.

Separate explicit writers remain legitimate: `ProductSalesStatusService`, `ProductSalesStatusBulkService`, product-deactivation documents, and explicitly confirmed audit/repair commands. They must not be invoked implicitly by normal product editing.

## Product summary paths

- `DefaultProductDesignService::recalculateProductSummary()` includes zero-price active variants and can zero a healthy product price; its live controller callers are retired in Task 1.
- `ProductVariantStructureService::recalculateProductSummary()` is called from product and purchase flows and becomes the one canonical rule in Task 3.
- Confirmed inventory repair commands remain separate repair tooling; Phase 5 adds no automatic price repair.

## Purchase routing

Purchase create/edit endpoints receive `variant_id`/`product_variant_id`, validate membership through structural constraints, and mutate the selected variant/warehouse row. There is no Base Variant resolver. Task 8 replaces validation with explicit ID resolution or an already-existing unambiguous canonical Base Variant; purchase never creates one.

## Reference surface

Known direct or semantic references include purchase items, invoice items, preinvoice items, draft reservations, warehouse stock, stock movements, transfers, stock counts, location stock/movements, inbound receipt items, sales-return items (including created variant), price-change items, product-deactivation history, invoice-collection revisions, seller-sales items, commission targets/rates/ledger, external `variety_id`, and activity-log evidence. Task 5 supplements this registry with database metadata discovery and treats every unknown reference as a blocker.
