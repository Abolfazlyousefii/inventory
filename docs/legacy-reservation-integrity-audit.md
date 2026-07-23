# Legacy reservation integrity audit

`inventory:audit-legacy-reservation-integrity` is a read-only diagnostic command for legacy rows in `preinvoice_draft_reservations` and `product_variants.reserved` cache differences.

## Usage

```bash
php artisan inventory:audit-legacy-reservation-integrity --format=csv
php artisan inventory:audit-legacy-reservation-integrity --format=json --output=reports/custom --order=301 --variant=10
```

Options:

- `--format=csv|json` selects report file format. `summary.json` is always JSON.
- `--output=` changes the report directory. The default is `reports/legacy-reservation-integrity` on the local disk.
- `--order=` limits rows to one `preinvoice_order_id`.
- `--variant=` limits rows to one `product_variants.id`.
- `--summary` is accepted for operational consistency; the command always writes summary output.

## Read-only safety

The command does not run migrations, create rows, update rows, delete rows, save models, or repair data. It installs a query guard that blocks write SQL before execution, and builds the report inside a database transaction so all reads share a consistent snapshot. Every summary includes:

- `data_changed=false`
- `stock_changed=false`
- `reserved_cache_changed=false`
- `preinvoice_changed=false`

`proposed-actions.csv` is advisory only. It never applies `CANDIDATE_*` actions.

## Reservation model

Legacy reservations are rows whose `reservation_scope` is `NULL`, an empty string, or outside `temporary_online`, `temporary_in_person`, and `official`.

Active recognized reservations have positive quantity, no `released_at`, no non-empty `release_reason`, and a recognized scope.

Protected document demand is calculated from positive `preinvoice_order_items.quantity` for protected preinvoice statuses, provided the order has no stock release timestamp, no related invoice, and at least one positive item. Expected reserved is:

```text
protected_document_demand + active_temporary_quantity
```

Official reservation quantity is reported separately and is not added again to expected reserved, avoiding double-counting protected document demand.

## Reports

The command writes:

- `summary.json`
- `legacy-reservation-rows.csv`
- `active-document-exact.csv`
- `active-document-mismatch.csv`
- `duplicate-legacy-and-official.csv`
- `invoiced-or-converted.csv`
- `cancelled-expired-or-released.csv`
- `unlinked-recent.csv`
- `unlinked-stale.csv`
- `invalid-reference.csv`
- `protected-demand-missing-reservation.csv`
- `variant-reconciliation.csv`
- `proposed-actions.csv`

Customer personal data such as customer name, mobile number, and address is intentionally excluded.

## Classification scope

Legacy-row classifications cover active document exact/short/excess cases, duplicate legacy and official rows, invoiced or converted documents, cancelled/expired/released documents, recent and stale unlinked rows, invalid product/variant references, and missing orders.

Variant reconciliation classifications report cache over-reserved, under-reserved, and matched variants. Cache actions are only recommendations; no `product_variants.reserved` update is performed.
