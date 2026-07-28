# Repair missing official preinvoice reservations

`inventory:repair-missing-official-reservations` is a narrowly scoped repair command for backfilling missing `official` rows in `preinvoice_draft_reservations` for one preinvoice order at a time.

## Safe usage

The command defaults to dry-run and refuses to run without `--order`:

```bash
php artisan inventory:repair-missing-official-reservations --order=301
```

Apply only after reviewing the dry-run report:

```bash
php artisan inventory:repair-missing-official-reservations --order=301 --apply
```

Optional report file:

```bash
php artisan inventory:repair-missing-official-reservations --order=301 --output=/tmp/repair-missing-official-reservations.json
```

## Guarantees

The command re-reads and locks the preinvoice row before validation. It only proceeds for active warehouse/finance statuses, requires `stock_released_at` to be `NULL`, requires no related invoice, and requires at least one positive item.

For each `product_id` and `variant_id`, it calculates:

- `required_quantity` from positive preinvoice item quantities.
- `official_quantity` from active official reservations.
- `missing_quantity = required_quantity - official_quantity`.

Only positive missing quantities are inserted. The inserted rows have `reservation_scope=official`, the target `preinvoice_order_id`, the correct product and variant ids, `quantity=missing_quantity`, `converted_at=now()`, `released_at=NULL`, and `release_reason=NULL`.

The command never updates warehouse stock, product or variant stock, reserved caches, preinvoice items, prices, customer fields, status, or totals. Reports always include `stock_changed=false`, `reserved_cache_changed=false`, and `preinvoice_changed=false`.
