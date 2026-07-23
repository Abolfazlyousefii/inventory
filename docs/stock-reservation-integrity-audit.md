# Stock Reservation Integrity Audit

این سند Command خواندنی `inventory:audit-stock-reservation-integrity` را توضیح می‌دهد. هدف، گزارش اختلاف Cache موجودی مرکزی، Cache رزروها، رزروهای موقت stale، رزروهای رسمی نامعتبر و تنوع‌های دارای قیمت صفر است؛ این ابزار هیچ مسیر apply/fix ندارد.

## اصول Read Only

- Command هیچ Controller، Model، Migration، Route یا روند فروش را تغییر نمی‌دهد.
- برای پیدا کردن انبار مرکزی از `WarehouseStockService::centralWarehouseId()` استفاده نمی‌شود، چون آن مسیر می‌تواند `firstOrCreate` داشته باشد.
- انبار مرکزی فقط با Query خواندنی از `warehouses` و شرط `type = central` پیدا می‌شود.
- اگر هیچ انبار مرکزی یا بیش از یک انبار مرکزی وجود داشته باشد، Command با خطا متوقف می‌شود.
- Guard قبل از اجرای Query فعال می‌شود و Statementهایی که با verbهای نوشتنی مانند `INSERT`، `UPDATE`، `DELETE`، `CREATE` و `ALTER` شروع شوند را قبل از اجرا Block می‌کند.
- خروجی گزارش روی disk محلی نوشته می‌شود و `summary.json` همیشه `data_changed=false` دارد.

## تعریف‌ها

- موجودی آزاد مرکزی هر تنوع: `SUM(warehouse_stocks.quantity)` فقط برای انباری که `warehouses.type = central` دارد.
- `product_variants.stock`: Cache موجودی آزاد انبار مرکزی.
- رزرو فعال واقعی: `SUM(preinvoice_draft_reservations.quantity)` برای ردیف‌هایی با `quantity > 0`، `released_at IS NULL` و `release_reason IS NULL`.
- رزرو فعال به تفکیک `reservation_scope` در ستون‌های `temporary_online_reserved`، `temporary_in_person_reserved` و `official_reserved` گزارش می‌شود.
- `product_variants.reserved`: Cache مجموع رزروهای فعال تنوع.
- موجودی کل تحت کنترل: `central_available_stock + active_reserved_quantity`.

## کدهای گزارش

- `S01`: اختلاف `product_variants.stock` با موجودی آزاد واقعی انبار مرکزی.
- `R01`: اختلاف `product_variants.reserved` با مجموع رزروهای فعال.
- `R02`: رزرو فعال برای product یا variant نامعتبر.
- `R03`: رزرو `temporary_online` منقضی‌شده که هنوز آزاد نشده است.
- `R04`: رزرو `temporary_in_person` قدیمی یا بدون heartbeat معتبر.
- `R05`: رزرو `official` فعال برای پیش‌فاکتوری که دیگر نیازمند رزرو نیست، `stock_released_at` دارد، یا به فاکتور تبدیل شده است.
- `P01`: تنوع active و sales enabled با `sell_price` صفر و موجودی آزاد مرکزی مثبت.
- `P02`: تنوع با `sell_price` صفر که فقط در انبار غیرمرکزی موجودی دارد.
- `P03`: تنوع با قیمت صفر و رزرو فعال.

## فایل‌های خروجی

- `summary.json`
- `central-stock-cache-desync.csv|json`
- `reservation-cache-desync.csv|json`
- `stale-temporary-reservations.csv|json`
- `invalid-official-reservations.csv|json`
- `central-stock-zero-prices.csv|json`
- `non-central-stock-zero-prices.csv|json`

همه ردیف‌ها ستون‌های محصول، تنوع، وضعیت فروش، موجودی cache، موجودی مرکزی، موجودی غیرمرکزی، رزروهای فعال تفکیک‌شده، `reservation_ids`، `preinvoice_order_ids`، کد ناهنجاری، شدت و اقدام پیشنهادی را دارند.

## دستور اجرا

```bash
php artisan inventory:audit-stock-reservation-integrity --format=csv --summary
```

خروجی JSON:

```bash
php artisan inventory:audit-stock-reservation-integrity --format=json --summary
```

محدودسازی دامنه:

```bash
php artisan inventory:audit-stock-reservation-integrity --product=123 --variant=456 --format=csv --summary
```
