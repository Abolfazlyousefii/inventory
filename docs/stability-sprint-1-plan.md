# خلاصه مدیریتی

این سند مرحله Review و Planning برای Sprint اول «امنیت اطلاعات» است و بر اساس `docs/stability-baseline-2026-07.md` و راستی‌آزمایی فایل‌های واقعی پروژه تهیه شده است. در این مرحله هیچ منطق برنامه، تست، Migration، Composer یا NPM تغییر داده نشد.

نتیجه اصلی: Sprint اول نباید روی Performance، UI یا Package تمرکز کند؛ اولویت باید روی جلوگیری از تغییر ناخواسته داده‌های مالی/انبار، دوباره‌کاری رزرو، ساخت سند تکراری، حذف اقلام و Rollback ناقص باشد.

حداکثر ۵ Patch پیشنهادی برای Sprint اول:

1. Idempotency و قفل‌گذاری امن تبدیل پیش‌فاکتور به فاکتور/حواله فروش.
2. یکپارچه‌سازی و تست مصرف/آزادسازی رزرو رسمی و موقت بدون double-apply.
3. محافظت از ویرایش اقلام انبار و برگشت دوباره به مالی با invariant تعداد/مبلغ.
4. پوشش ثبت ارسال بار با idempotency، status guard و تست ActivityLog بدون اطلاعات حساس.
5. Guard تخفیف ردیفی/کلی و گردش حساب مشتری هنگام ویرایش مالی و تبدیل سند.

# وضعیت گزارش Baseline

- فایل Baseline پیدا شد و کامل خوانده شد: `docs/stability-baseline-2026-07.md`.
- Baseline گزارش کرده بود 237 تست با Scan استاتیک شناسایی شده، اما 0 تست واقعاً اجرا شده چون Artisan قبل از Bootstrap به دلیل نبود `vendor/autoload.php` متوقف شده است.
- Baseline مشکلات را در چند محور ثبت کرده بود: محیط تست، Migrationهای MySQL-only، Query/N+1، جریان‌های مالی/انبار، Composer/NPM audit نامطمئن و Build موفق.
- موارد Performance، UI و Dependency برای Sprint اول کنار گذاشته شدند مگر اینکه مستقیماً باعث خرابی داده شوند.

# مشکلات تأییدشده

## A. خطر مستقیم اطلاعات

| عنوان | فایل/خط تقریبی | تابع/کلاس | شواهد واقعی | هنوز در کد وجود دارد؟ | جریان‌های تحت اثر |
|---|---|---|---|---|---|
| تبدیل پیش‌فاکتور به فاکتور باید idempotency کامل داشته باشد | `app/Http/Controllers/PreinvoiceController.php:2559-2575` | `PreinvoiceController::finalize` | عملیات داخل `DB::transaction` است و Order و Invoice احتمالی با `lockForUpdate` قفل می‌شوند. این بخش تا حد زیادی اصلاح شده، اما باید با Regression تست شود. | بله، مسیر حساس هنوز وجود دارد؛ شواهد قفل مثبت است، نه False Positive | تأیید مالی، تبدیل به فاکتور، رزرو، Ledger |
| بازنویسی اقلام Invoice موجود هنگام finalize می‌تواند در صورت خطای میانی باعث ریسک حذف/بازسازی شود | `app/Http/Controllers/PreinvoiceController.php:2645-2665` و ادامه ساخت اقلام | `PreinvoiceController::finalize` | اگر Invoice قبلی پیدا شود، `items()->delete()` و سپس اقلام دوباره ساخته می‌شوند. داخل Transaction است، اما نیازمند تست rollback و invariant تعداد/مبلغ است. | بله | تبدیل به فاکتور، اقلام فاکتور، Ledger |
| مسیر قدیمی/جداگانه ساخت حواله از رکورد مالی idempotency ضعیف‌تری نسبت به finalize دارد | `app/Services/SalesHavalehService.php:711-765` | `SalesHavalehService::createFromFinancialRecord` | Order قفل می‌شود، اما Invoice موجود با `where(...)->first()` بدون `lockForUpdate` خوانده می‌شود؛ سپس اقلام ساخته و Stock با Query دوباره از `invoice->items()` پیدا می‌شود. | بله | تبدیل رکورد مالی به حواله، موجودی، سند تکراری |
| مصرف رزرو رسمی باید در برابر اجرای تکراری اثبات شود | `app/Services/PreinvoiceReservationService.php:269-305` | `consumeOfficialReservationsForOrder` | رزروهای رسمی با شرط `released_at` و `release_reason` خالی و `lockForUpdate` مصرف می‌شوند و `release_reason='consumed'` ثبت می‌شود. شواهد مثبت است، اما تست idempotency لازم است. | بله؛ خطر نیازمند Test است، نه الزاماً نقص قطعی | رزرو، تبدیل به فاکتور، موجودی |
| آزادسازی رزرو رسمی/موقت با حلقه روی rows و تغییر stock باید double-release نشود | `PreinvoiceReservationService.php:238-265`, `PreinvoiceDraftReservationService.php:102-118`, `145-197` | Release methods | همه داخل Transaction و lock هستند، اما مسیرهای متعدد release وجود دارد و علت مشترک «چند منبع release» است. | بله | آزادسازی رزرو، Draft، Expiry |
| ویرایش اقلام انبار Query/Lock داخل Loop دارد و مستقیماً موجودی و مبلغ را تغییر می‌دهد | `app/Services/WarehouseCollectionService.php:69-120`, `260-345` | `updateCollectedItems`, `updateInvoiceItemsInPlace` | Invoice و Items قفل می‌شوند، اما Variantها در Loop جدا قفل/خوانده می‌شوند؛ قیمت/تخفیف/تعداد و Stock تحت اثر است. | بله | ویرایش انبار، برگشت به مالی، موجودی، مبلغ |
| تغییر اقلام حواله و sync پیش‌فاکتور لینک‌شده می‌تواند تعداد/مبلغ را بازنویسی کند | `app/Services/SalesHavalehService.php:40-120`, `319-365` | `updateItemsForInvoice`, `syncLinkedPreinvoiceForFinanceReapproval` | عملیات Transaction دارد، اما حذف/ساخت اقلام و برگشت به مالی باید invariant تست شود. | بله | ویرایش انبار، برگشت مالی، اقلام فاکتور، مبلغ |
| ثبت ارسال بار تست مستقیم ندارد، اما وضعیت سند را نهایی می‌کند | `app/Http/Controllers/WarehouseShippingController.php:47-99` | `WarehouseShippingController::ship` | داخل Transaction، Invoice با `lockForUpdate` قفل می‌شود و status به `SHIPPED` تغییر می‌کند؛ Notification بعد از Transaction است. خطر اصلی کمبود Regression Test است. | بله | ثبت نهایی فاکتور، ارسال بار، status سند |
| ویرایش مالی مقدار، تخفیف و رزرو را تغییر می‌دهد | `app/Services/FinancePreinvoiceEditorService.php:16-130` | `FinancePreinvoiceEditorService::update` | Order و Items قفل می‌شوند، set اقلام باید دقیقاً برابر Payload باشد، دلتا رزرو اعمال می‌شود و ActivityLog ثبت می‌شود. نیازمند تست invariant تخفیف/رزرو است. | بله | تأیید مالی، تخفیف، رزرو، مبلغ سند |

## B. زیرساخت تست

| عنوان | شواهد | طبقه‌بندی |
|---|---|---|
| نبود `vendor/autoload.php` | همه Artisan commandها قبل از Bootstrap متوقف شده‌اند | مشکل محیط تست، نه Sprint اول Data Safety |
| Migrationهای MySQL-only در مسیر تست SQLite | Baseline دو migration `up()` پرریسک معرفی کرده است | Sprint 2، مگر قبل از تست Sprint اول مانع CI شود |
| `npm audit` و `npm outdated` با 403 | آسیب‌پذیری Frontend قابل قضاوت نیست | خارج از Sprint اول |

## C. عملکرد

| عنوان | شواهد | تصمیم Sprint اول |
|---|---|---|
| Query داخل Loop در Warehouse/SalesHavaleh/Purchase | Baseline Q-04 تا Q-08 را High/Medium معرفی کرده است | فقط مواردی وارد Sprint اول می‌شوند که مستقیماً موجودی/اقلام/مبلغ را تغییر می‌دهند؛ صرف N+1 وارد Sprint اول نیست |
| View Composer و BugInvestigator N+1 | اثر مستقیم داده ندارد | خارج از Sprint اول |

## D. معماری و نگهداری

| عنوان | شواهد | تصمیم |
|---|---|---|
| `PreinvoiceController` بسیار بزرگ است | مسیرهای ساخت، ویرایش، رزرو، مالی و تبدیل در یک Controller دیده می‌شود | Refactor گسترده ممنوع؛ فقط Guardهای کوچک و تست Regression |
| چند مسیر تغییر موجودی/رزرو | `PreinvoiceReservationService`, `PreinvoiceDraftReservationService`, `SalesHavalehService`, `WarehouseCollectionService` | تمرکز Sprint اول روی invariant و test، نه بازنویسی معماری |
| Validation پراکنده | Request و Controller و Serviceها Validation دارند | فقط Validationهای داده‌محور لازم اضافه شود |

## E. Frontend و UX

| عنوان | شواهد | تصمیم |
|---|---|---|
| Submit دوباره در تبدیل/ارسال/ویرایش ممکن است رخ دهد | کد backend باید idempotent باشد؛ UI تنها کافی نیست | اگر Patch backend idempotency نیازمند token/guard کوچک باشد وارد Sprint اول می‌شود |
| JS بزرگ و DOM سنگین Product Export | Baseline ثبت کرده | خارج از Sprint اول |

## F. Dependency

| عنوان | شواهد | تصمیم |
|---|---|---|
| Composer audit غیرقطعی | vendor نصب نیست | Sprint 4 |
| npm audit غیرقطعی | 403 Registry | Sprint 4 |
| چند موتور PDF | `dompdf` و `mpdf` در Composer | Sprint 4؛ Sprint اول ممنوع |

# False Positiveها

| مورد Baseline | نتیجه راستی‌آزمایی | دلیل |
|---|---|---|
| «اکثر جریان‌های حساس بدون Transaction نیستند» | False Positive برای Sprint اول | کد واقعی برای finalize، reservation، warehouse collection، finance edit و shipping Transaction و lock دارد. مشکل اصلی نبود تست و idempotency اثبات‌شده است، نه فقدان کامل Transaction. |
| Product Export بدون Transaction | False Positive برای Data Safety | خروجی محصول read-only است و Transaction برای Sprint اول لازم نیست. |
| ثبت ارسال بار بدون Transaction | False Positive | `WarehouseShippingController::ship` داخل `DB::transaction` است و Invoice را `lockForUpdate` می‌کند. |
| Guardهای MySQL در برخی Migrationها | False Positive برای ناسازگاری SQLite | چند migration مثل model_lists, activity_logs, warehouse_stock index و stock_movements مسیر SQLite/Guard دارند. |
| Performance-only N+1ها | False Positive برای Sprint اول | N+1های View/BugInvestigator مستقیم داده مالی/انبار را خراب نمی‌کنند. |

# مشکلات محیط تست

1. `vendor/autoload.php` موجود نیست و هیچ تستی اجرا نشده است.
2. Migrationهای MySQL-only در مسیر SQLite ممکن است بعد از نصب vendor اجرای تست را متوقف کنند.
3. `npm audit` و `npm outdated` به دلیل 403 Registry قابل اتکا نیستند.
4. Notification runtime در تست بدون Bootstrap قابل تأیید نیست.

این موارد مهم هستند، اما Sprint اول Data Safety فقط در حد ساخت Regression Testهای لازم و اجرای آن‌ها پس از رفع محیط تست به آن وابسته است. رفع کامل محیط تست در Sprint 2 قرار می‌گیرد، مگر به عنوان پیش‌نیاز اجرایی برای تست Patchهای Sprint 1.

# پنج ریسک اصلی اطلاعات

1. **ساخت فاکتور/حواله تکراری یا ناقص هنگام تبدیل پیش‌فاکتور**: اگر درخواست دوباره یا خطای میانی رخ دهد، باید تنها یک Invoice معتبر با اقلام کامل باقی بماند.
2. **مصرف یا آزادسازی دوباره رزرو**: مسیرهای رسمی، موقت، Expiry و تبدیل نهایی همگی موجودی reserved را تغییر می‌دهند.
3. **حذف/بازسازی اقلام فاکتور بدون invariant**: finalize و ویرایش انبار می‌توانند اقلام را delete/create یا normalize کنند.
4. **تغییر ناخواسته مبلغ و تخفیف**: finance edit و warehouse edit روی price، line discount، invoice discount و total اثر مستقیم دارند.
5. **تغییر وضعیت نهایی بدون تست برگشت‌پذیری**: shipping، conversion، finance reapproval و cancellation مسیرهای status-sensitive هستند.

# Sprint اول پیشنهادی

Sprint اول شامل ۵ Patch مستقل و قابل تست است. Performance، UI، Package update، PDF و Refactor گسترده عمداً حذف شده‌اند.

## Patch 1 — Idempotency تبدیل پیش‌فاکتور به فاکتور/حواله فروش

1. **عنوان Patch**: `fix: make preinvoice conversion idempotent and atomic`
2. **شدت**: Critical
3. **علت ریشه‌ای**: مسیرهای تبدیل حساس‌اند و یک مسیر `SalesHavalehService::createFromFinancialRecord` Invoice موجود را بدون lock می‌خواند؛ مسیر `finalize` بهتر شده ولی بازسازی اقلام موجود باید با Regression Test اثبات شود.
4. **فایل‌های مرتبط**:
   - `app/Http/Controllers/PreinvoiceController.php`
   - `app/Services/SalesHavalehService.php`
   - Migration/Index مرتبط با یکتایی `preinvoice_order_id`
5. **اطلاعات در معرض خطر**: Invoice، InvoiceItem، CustomerLedger، ProductVariant stock/reserved، وضعیت Preinvoice.
6. **تغییر پیشنهادی**:
   - در مسیرهای تبدیل، Invoice موجود با `lockForUpdate` و کلیدهای `preinvoice_order_id/uuid` خوانده شود.
   - اگر Invoice موجود کامل و متعلق به همان Preinvoice است، بدون delete/recreate برگردد.
   - اگر Invoice ناقص است، خطای کنترل‌شده و قابل Audit بدهد، نه بازسازی بی‌قید.
   - ساخت آیتم‌ها به‌گونه‌ای باشد که آخرین آیتم با Query مجدد از رابطه پیدا نشود.
7. **تست Regression لازم**:
   - Submit دوباره یک Preinvoice فقط یک Invoice و تعداد ثابت InvoiceItem بسازد.
   - خطا بعد از ساخت بخشی از اقلام باعث Rollback کامل شود.
   - UUID تکراری برای Preinvoice دیگر خطای کنترل‌شده بدهد.
   - Ledger فقط یک debit برای Invoice داشته باشد.
8. **تست دستی لازم**:
   - تبدیل یک پیش‌فاکتور با چند آیتم، رفرش/Submit مجدد، بررسی تعداد Invoice/Items/Reserved.
9. **ریسک تغییر**: Medium/High؛ چون مسیر مالی نهایی است.
10. **وابستگی به Patch دیگر**: ندارد؛ اولین Patch است.
11. **Commit پیشنهادی**: `fix: make preinvoice conversion idempotent and atomic`
12. **معیار Done**:
   - یک Preinvoice هرگز بیش از یک Invoice فعال نسازد.
   - تعداد اقلام Invoice برابر اقلام Preinvoice باشد.
   - مبلغ نهایی بدون تغییر ناخواسته بماند.
   - Rollback در Failure کامل باشد.

## Patch 2 — محافظت رزرو در برابر double consume / double release

1. **عنوان Patch**: `fix: guard reservation consume and release idempotency`
2. **شدت**: Critical
3. **علت ریشه‌ای**: چند مسیر release/consume برای رزرو رسمی و موقت وجود دارد؛ قفل‌ها مثبت‌اند ولی تست idempotency کافی نیست.
4. **فایل‌های مرتبط**:
   - `app/Services/PreinvoiceReservationService.php`
   - `app/Services/PreinvoiceDraftReservationService.php`
   - `app/Services/InventoryReservationReleaseService.php`
5. **اطلاعات در معرض خطر**: `reserved` در Product/ProductVariant، reservation rows، Preinvoice status.
6. **تغییر پیشنهادی**:
   - متدهای consume/release idempotent و explicit شوند.
   - هر reservation فقط یک بار بتواند stock delta اعمال کند.
   - حالت‌های `released_at`, `release_reason='consumed'`, `converted_at` به صورت واحد Validate شوند.
7. **تست Regression لازم**:
   - دوبار consume روی یک order، reserved را دوبار کم نکند.
   - دوبار release روی یک token/order، reserved را دوبار آزاد نکند.
   - Expiry بعد از conversion هیچ تغییری در stock/reserved ندهد.
8. **تست دستی لازم**:
   - رزرو موقت، ذخیره Draft، Submit، Expire و تبدیل نهایی با بررسی reserved قبل/بعد.
9. **ریسک تغییر**: Medium؛ دامنه محدود به Reservation serviceها.
10. **وابستگی به Patch دیگر**: بهتر است بعد از Patch 1 اجرا شود، چون conversion مصرف رزرو را صدا می‌زند.
11. **Commit پیشنهادی**: `fix: prevent duplicate reservation consume and release`
12. **معیار Done**:
   - هر reservation delta دقیقاً یک بار اعمال شود.
   - هیچ reserved منفی نشود.
   - اجرای تکراری عملیات خروجی یکسان داشته باشد.

## Patch 3 — invariant ویرایش اقلام انبار و برگشت به مالی

1. **عنوان Patch**: `fix: preserve invoice item and total invariants during warehouse edits`
2. **شدت**: High
3. **علت ریشه‌ای**: ویرایش اقلام انبار موجودی، مبلغ، فاکتور و پیش‌فاکتور لینک‌شده را همزمان تغییر می‌دهد و بخشی از Lockها در Loop انجام می‌شوند.
4. **فایل‌های مرتبط**:
   - `app/Services/WarehouseCollectionService.php`
   - `app/Services/SalesHavalehService.php`
   - `app/Services/WarehouseStockService.php`
5. **اطلاعات در معرض خطر**: InvoiceItem، ProductVariant stock، WarehouseStock، Invoice total، Preinvoice linked items.
6. **تغییر پیشنهادی**:
   - قبل/بعد تعداد کل اقلام، مجموع quantity و total محاسبه و Assert شود.
   - Variantهای درگیر به صورت دسته‌ای preload/lock شوند؛ تغییر بزرگ معماری انجام نشود.
   - در صورت خطا، هیچ stock movement یا item partial باقی نماند.
7. **تست Regression لازم**:
   - حذف، افزودن و تغییر quantity در یک عملیات، stock delta درست بدهد.
   - تغییر قیمت بدون دلیل رد شود.
   - برگشت به مالی، preinvoice linked items را دقیقاً مطابق Invoice نگه دارد.
8. **تست دستی لازم**:
   - فاکتور جمع‌آوری‌شده با چند آیتم، تغییر یک آیتم، حذف یک آیتم و ارسال مجدد به مالی.
9. **ریسک تغییر**: Medium/High؛ به موجودی و مالی وصل است.
10. **وابستگی به Patch دیگر**: بعد از Patch 1 و 2 امن‌تر است.
11. **Commit پیشنهادی**: `fix: preserve warehouse edit item and total invariants`
12. **معیار Done**:
   - تعداد اقلام ناخواسته تغییر نکند.
   - total فقط مطابق تغییر مجاز عوض شود.
   - stock delta دقیقاً برابر اختلاف quantity باشد.

## Patch 4 — ثبت ارسال بار قابل تکرار و قابل Audit

1. **عنوان Patch**: `fix: make shipping finalization idempotent and tested`
2. **شدت**: High
3. **علت ریشه‌ای**: shipping status نهایی است و تست مستقیم ندارد؛ هرچند Transaction و lock در کد وجود دارد.
4. **فایل‌های مرتبط**:
   - `app/Http/Controllers/WarehouseShippingController.php`
   - `app/Models/Invoice.php`
   - ActivityLog model/table
5. **اطلاعات در معرض خطر**: Invoice status، shipped_at/by، shipping cost/note، ActivityLog.
6. **تغییر پیشنهادی**:
   - اگر Invoice قبلاً shipped است، رفتار کنترل‌شده و idempotent مشخص شود.
   - ActivityLog بدون اطلاعات محرمانه و بدون duplicate غیرضروری ثبت شود.
   - تست مستقیم Feature اضافه شود.
7. **تست Regression لازم**:
   - دوبار ارسال یک Invoice، سند دوم یا status اشتباه نسازد.
   - shipping_cost فارسی/انگلیسی normalize شود.
   - status غیر `READY_TO_SHIP` رد شود.
8. **تست دستی لازم**:
   - ثبت ارسال، Back/Refresh/Submit دوباره، بررسی status و ActivityLog.
9. **ریسک تغییر**: Low/Medium.
10. **وابستگی به Patch دیگر**: ندارد، اما بعد از Patch 3 منطقی‌تر است.
11. **Commit پیشنهادی**: `fix: make shipping finalization idempotent and tested`
12. **معیار Done**:
   - status نهایی فقط یک بار اعمال شود.
   - ActivityLog حساس و تکراری نباشد.
   - Rollback در خطا کامل باشد.

## Patch 5 — Guard تخفیف و Ledger در ویرایش مالی

1. **عنوان Patch**: `fix: guard finance discounts and ledger totals`
2. **شدت**: High
3. **علت ریشه‌ای**: finance edit مقدار line/product/invoice discount و total را تغییر می‌دهد و بعداً conversion/ledger از همین total استفاده می‌کند.
4. **فایل‌های مرتبط**:
   - `app/Services/FinancePreinvoiceEditorService.php`
   - `app/Support/SalesDocumentTotals.php`
   - `app/Services/CustomerLedgerService.php`
5. **اطلاعات در معرض خطر**: total_price، discount_breakdown، invoice ledger debit، item totals.
6. **تغییر پیشنهادی**:
   - قبل/بعد totals و discount breakdown invariant تست شود.
   - منفی/بیش از subtotal بودن discount در همه مسیرها رد شود.
   - Ledger بعد از conversion با total نهایی reconcile شود.
7. **تست Regression لازم**:
   - تخفیف ردیفی + کلی همزمان total درست بسازد.
   - discount بیش از subtotal رد شود.
   - Ledger debit برابر Invoice total نهایی باشد.
8. **تست دستی لازم**:
   - ویرایش مالی با چند محصول، تخفیف محصولی و تخفیف کلی، سپس finalize.
9. **ریسک تغییر**: Medium.
10. **وابستگی به Patch دیگر**: بعد از Patch 1 بهتر است، چون Ledger در conversion نهایی می‌شود.
11. **Commit پیشنهادی**: `fix: guard finance discounts and ledger totals`
12. **معیار Done**:
   - total و ledger برابر بمانند.
   - هیچ discount نامعتبر ذخیره نشود.
   - Activity/Audit Log بدون داده محرمانه ثبت شود.

# ترتیب اجرای Patchها

1. `fix: make preinvoice conversion idempotent and atomic`
2. `fix: prevent duplicate reservation consume and release`
3. `fix: preserve warehouse edit item and total invariants`
4. `fix: make shipping finalization idempotent and tested`
5. `fix: guard finance discounts and ledger totals`

علت ترتیب: تبدیل به فاکتور نقطه اتصال مالی، اقلام، Ledger و مصرف رزرو است؛ سپس رزرو، سپس ویرایش انبار، سپس ارسال نهایی، و در پایان Guard تخفیف/Ledger که به خروجی نهایی Patch اول وابسته است.

# تست‌های لازم

## تست‌های Regression مشترک Sprint

- هیچ تستی روی دیتابیس واقعی `inventory` اجرا نشود.
- تمام تست‌ها با SQLite `:memory:` یا دیتابیس تست ایزوله اجرا شوند.
- تعداد اقلام قبل/بعد عملیات حساس Assert شود.
- مبلغ قبل/بعد فقط در صورت تغییر مجاز Assert شود.
- reserved/stock قبل/بعد با delta دقیق Assert شود.
- اجرای تکراری Request سند تکراری نسازد.
- Failure میانی Rollback کامل ایجاد کند.
- ActivityLog بدون password/token/session/customer full payload باشد.

## تست‌های کمبود فعلی قبل از اصلاح

| کمبود | تست پیشنهادی |
|---|---|
| Submit دوباره conversion | `PreinvoiceConversionIdempotencyTest` |
| rollback بعد از ساخت بخشی از InvoiceItem | تست با Mock/Exception کنترل‌شده داخل Transaction |
| double consume/release reservation | `PreinvoiceReservationIdempotencyTest` |
| ارسال بار تکراری | `WarehouseShippingIdempotencyTest` |
| حفظ اقلام و مبلغ در warehouse edit | توسعه `WarehouseCollectionItemAdjustmentTest` |
| Ledger برابر Invoice total نهایی | توسعه `CustomerLedgerCancelledInvoiceTest` یا تست جدید ledger conversion |

# معیار انتشار

انتشار Sprint اول فقط زمانی مجاز است که:

- تمام Regression Testهای Sprint پاس شوند.
- هیچ تستی روی دیتابیس واقعی اجرا نشده باشد.
- تعداد اقلام قبل و بعد عملیات حساس برابر یا مطابق تغییر مجاز باشد.
- مبلغ قبل و بعد ناخواسته تغییر نکند.
- رزرو دوبار اعمال یا آزاد نشود.
- Request تکراری سند تکراری نسازد.
- Failure باعث Rollback کامل شود.
- Log حساس بدون اطلاعات محرمانه ثبت شود.
- Backup و Rollback Plan وجود داشته باشد.
- Patchها کوچک و مستقل بمانند و هر Patch Commit جداگانه داشته باشد.

# موارد خارج از Sprint اول

## موارد ممنوع در Sprint اول

- Refactor گسترده.
- ارتقای Laravel.
- تعویض موتور PDF.
- نصب کتابخانه UI.
- تغییر کامل معماری.
- Update همه Packageها.
- تغییر هم‌زمان موجودی و مالی در یک Patch بزرگ.
- بازنویسی تمام Migrationها.
- تغییر Schema بدون ضرورت مستقیم برای Data Safety.
- تغییر مستقیم داده‌های موجود.
- Performance-only N+1 بدون اثر مستقیم بر داده.
- Product Export UI/print redesign.
- Composer/NPM audit remediation یا حذف Packageها.

## موارد منتقل‌شده به Sprintهای بعدی

- رفع کامل Bootstrap و vendor/CI: Sprint 2.
- اصلاح Migrationهای SQLite-only برای اجرای تست: Sprint 2، مگر پیش‌نیاز فوری تست Sprint 1 شود.
- Bulk performance و N+1 عمومی: Sprint 3.
- Frontend/Inline JS/Product Export UX: Sprint 4.
- Dependency audit و تصمیم PDF engine: Sprint 4.
