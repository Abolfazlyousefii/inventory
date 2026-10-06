# بازصدور ایمن فاکتور لغوشده 01735 به پیش‌نویس جدید

این عملیات برای وضعیت مشخص زیر طراحی شده است؛ هیچ داده عملیاتی نباید از SQL Dump دوباره وارد شود.

- Invoice: `01735` / `invoices.id=2083` / `status=not_shipped`
- Preinvoice: `preinvoice_orders.id=1796` / `status=cancelled_by_finance`
- Seller: `users.id=115` (یاسین شیرمحمدلی)
- Warehouse return: `warehouse_inbound_receipts.id=358`, receipt `WI-000358`, `received`, expected/accepted `951/951`
- Invoice payments: `0` (re-validated at execution time)

## Design

The command creates a *new* five-digit-numbered `preinvoice_orders` record plus draft items copied from the cancelled **invoice items**. Its seller and creator both become user 115.

No original document, original return receipt, invoice payment, commission, customer ledger or warehouse stock is modified. The new draft has no reservation token, stock freeze or official reservation. Seller must re-check availability and prices before submitting it.

The `cancelled_invoice_reissues` table records the one-to-one mapping, with unique original invoice ID and unique new draft ID. The default invoice-cancellation page excludes mapped rows, while its `?reissued=1` history tab displays them, including the replacement draft code. Original cancellation status is retained. Deleting a replacement draft removes its mapping by FK cascade, so the original invoice returns to the default cancelled list.

## Local validation first (test/sandbox database)

```bash
php artisan migrate:status
php artisan migrate --path=database/migrations/2026_10_06_000000_create_cancelled_invoice_reissues_table.php
php artisan test --filter=CancelledInvoiceReissueTest
php artisan test
```

If local DB contains a verified copy of invoice 01735, run the **read-only** preview:

```bash
php artisan invoices:reissue-cancelled-as-draft 01735 --seller-id=115 --invoice-id=2083 --receipt-id=358
```

## Production change control — only after local tests pass and branch is merged

1. Take a verified complete database backup. Do not paste or import the development SQL dump into Production.
2. Check `git status` and ensure Production has no uncommitted app changes; deploy merged `main`.
3. Inspect `php artisan migrate:status` before running any migration. Execute **only** the new migration if earlier migrations are out of sync; investigate unusual status instead of running unrestricted migrations blindly.
4. Run `php artisan migrate --path=database/migrations/2026_10_06_000000_create_cancelled_invoice_reissues_table.php --force` as appropriate.
5. Run the exact preview command shown above; verify all IDs, name, receipt and 951 units.
6. After explicit operator approval, run:

```bash
php artisan invoices:reissue-cancelled-as-draft 01735 --seller-id=115 --invoice-id=2083 --receipt-id=358 --note="بازصدور به درخواست مدیریت جهت ثبت مجدد توسط فروشنده" --apply
```

7. Record the new five-digit draft code from stdout. Confirm it appears for seller 115 in the drafts tab and opens for editing.
8. Confirm invoice 01735 remains cancelled; receipt WI-000358 remains received with 951 accepted; no new reservations, stock movements, payments or customer debits appeared from this operation. Confirm that the standard cancellation list no longer shows 01735 while the `?reissued=1` history tab does.
9. Seller should adjust quantities against **current** stock and submit via the ordinary workflow. This operation does *not* reserve 951 units automatically.

The command defaults to preview; running with `--apply` is the only write path. It refuses missing/incorrect invoice or receipt IDs, incomplete returns, previously reissued invoices, nonmatching ownership or payments. Re-running with `--apply` must fail rather than create a duplicate.
