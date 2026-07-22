# خلاصه مدیریتی

این مرحله فقط ابزار ممیزی Read Only برای شناسایی قیمت‌های صفر، قیمت‌های ناسازگار Product/Variant، ردیف‌های سند با قیمت صفر و پیشنهاد قیمت قابل بازیابی می‌سازد. Command هیچ مسیر apply/fix ندارد و روی دیتابیس فقط Queryهای خواندنی اجرا می‌کند.

# ساختار قیمت پروژه

- محصول بدون تنوع از `products.price` به‌عنوان قیمت فروش جاری استفاده می‌کند.
- محصول تنوع‌دار از `product_variants.sell_price` به‌عنوان قیمت فروش مؤثر هر تنوع استفاده می‌کند.
- `products.price` در چند مسیر به‌صورت Summary/Legacy نگهداری می‌شود و ممکن است با تنوع‌ها Desync شود.
- `product_variants.buy_price` بهای خرید جاری تنوع و `purchase_items.buy_price` Snapshot خرید است.
- `invoice_items.price` و `preinvoice_order_items.price` Snapshot تاریخی قیمت خط هستند و نباید خودکار تغییر کنند.

# تعریف قیمت مؤثر

- بدون تنوع معتبر: `products.price`.
- با تنوع معتبر: `product_variants.sell_price`.
- سابقه خرید فقط قرینه است و بدون Margin Rule رسمی قیمت فروش پیشنهادی قطعی نمی‌سازد.

# تعداد قیمت‌های صفر

Command در `price-integrity-summary-YYYYMMDD-HHmm.json` تعداد `product_zero_prices` و `variant_zero_sell_prices` را گزارش می‌کند.

# موارد Critical

A01، A02، A03، A09، A10، A11، A12 و A15 با شدت Critical گزارش می‌شوند.

# موارد High

A06 برای `buy_price` صفر همراه با موجودی یا سابقه خرید با شدت High گزارش می‌شود.

# موارد Medium

A04، A05، A08 و A16 با شدت Medium گزارش می‌شوند.

# موارد طبیعی

تنوع غیرفعال و بدون موجودی با قیمت صفر با A07/Low یا برای اصلاح دستی کم‌اولویت گزارش می‌شود.

# محصولات دارای موجودی و قیمت صفر

با موجودی واقعی از `warehouse_stocks`، نه Cacheهای `products.stock` یا `product_variants.stock`، محاسبه می‌شود.

# محصولات دارای خرید و قیمت صفر

از جدول واقعی `purchase_items` و ستون‌های `product_id` / `product_variant_id` محاسبه می‌شود.

# اسناد تاریخی قیمت صفر

- A11: ردیف پیش‌فاکتور با `price <= 0` و `quantity > 0`.
- A12: ردیف فاکتور با `price <= 0` و `quantity > 0`.
- این اسناد فقط برای حسابداری گزارش می‌شوند و نباید خودکار تغییر کنند.

# اختلاف موجودی Summary و Warehouse

موجودی واقعی از `SUM(warehouse_stocks.quantity)` محاسبه می‌شود و Cacheهای Product/Variant فقط برای مقایسه در خروجی می‌آیند.

# علت‌های ریشه‌ای احتمالی

1. ساخت تنوع جدید با `sell_price = 0` یا مقدار خالی تبدیل‌شده به صفر.
2. Summary محصول از تنوع صفر محاسبه/نگهداری شده است.
3. خرید یا ویرایش محصول فیلد فروش را ناخواسته با مقدار صفر جایگزین کرده است.
4. Sync/Import فیلد قیمت ارسال‌نشده را صفر در نظر گرفته است.
5. Client در پیش‌فاکتور قیمت را ارسال کرده و Backend همان Snapshot را ثبت کرده است.

# مسیرهای کد مشکوک

- `app/Http/Controllers/ProductController.php`: ساخت/ویرایش Product و Variant و مقداردهی `sell_price`/`buy_price`.
- `app/Http/Controllers/PurchaseController.php`: ثبت خرید، دریافت قیمت خرید/فروش از فرم و به‌روزرسانی قیمت Variant.
- `app/Http/Controllers/PreinvoiceController.php`: قیمت خط پیش‌فاکتور و تعامل Client/Backend.
- `app/Http/Controllers/PreinvoiceApiController.php`: قیمت‌های API پیش‌فاکتور.
- `app/Http/Controllers/PriceChangeDocumentController.php`: تغییر قیمت برنامه‌ریزی‌شده و Snapshot قدیم/جدید.
- `app/Services/CrmProductSyncService.php` و Webhook/Job/Commandهای Sync در صورت وجود.

# روش پیشنهاد قیمت

اولویت پیشنهاد: آخرین فروش مثبت همان Variant، آخرین Price Change مثبت، Median فروش‌های اخیر، قیمت Product، تنوع مشابه/خواهر، پیش‌فاکتور معتبر. قیمت خرید بدون Margin Rule رسمی فقط به‌عنوان قرینه گزارش می‌شود و Suggested Price تولید نمی‌کند.

# موارد بدون قیمت قابل بازیابی

اگر فروش مثبت، Price Change مثبت یا پیش‌فاکتور مثبت پیدا نشود، `confidence=None` و `requires_manual_review=true` ثبت می‌شود.

# موارد نیازمند قیمت‌گذاری دستی

هر رکورد Critical بدون Suggested Price معتبر باید در مرحله دوم دستی قیمت‌گذاری شود.

# پلن اصلاح مرحله دوم

1. بررسی Backup تأییدشده.
2. بازبینی CSV anomalies و suggestions.
3. تأیید قیمت‌های High Confidence توسط مسئول فروش/مالی.
4. تهیه اسکریپت اصلاح جداگانه با dry-run و transaction.
5. گزارش جداگانه اسناد تاریخی صفر برای حسابداری بدون تغییر خودکار آنها.
