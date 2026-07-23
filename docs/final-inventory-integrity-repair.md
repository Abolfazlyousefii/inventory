# Final Inventory Integrity Repair Patch

This patch adds two explicit-apply Artisan commands and creates no migrations.

## `inventory:repair-reserved-cache`

Dry-run by default. It computes each variant's expected reserved cache as:

```text
protected_document_demand + active_temporary_quantity
```

Protected document demand is derived only from positive `preinvoice_order_items.quantity` for active preinvoice statuses, unreleased stock, and orders without invoices. Official reservation rows are intentionally not added to this total, preventing double counting of preinvoice demand.

Variants present in `cancelled_by_finance` orders with `stock_released_at IS NULL` are excluded from apply and reported in `unresolved-cancelled-finance.csv`.

Apply only updates `product_variants.reserved`, then rebuilds `products.reserved` from variant sums. It never edits warehouse stock, stock caches, preinvoice rows, reservation rows, invoices, prices, or document status.

## `inventory:quarantine-zero-price-variants`

Dry-run by default. It selects active, sales-enabled variants with `sell_price <= 0` and positive central or non-central warehouse stock.

Apply only changes `product_variants.sales_enabled` to `false`. Prices, stock, reserved values, and documents are not changed.
