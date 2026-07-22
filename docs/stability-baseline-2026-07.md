# مشخصات محیط

- تاریخ تهیه گزارش: 2026-07-22 UTC
- مسیر کاری در کانتینر: `/workspace/inventory`
- شاخه Git: `work`
- PHP: `PHP 8.4.22-dev (cli)` با `Zend OPcache` و `Xdebug 3.5.2-dev`
- Laravel Artisan: قابل اجرا نبود؛ `vendor/autoload.php` وجود ندارد.
- Composer: `Composer version 2.9.7 2026-04-14`
- Node: `v20.20.2`
- npm: `11.4.2`
- `composer show laravel/framework`: به دلیل نبود نصب vendor، Package پیدا نشد.
- `composer show pestphp/pest`: به دلیل نبود نصب vendor، Package پیدا نشد.
- `composer show phpunit/phpunit`: به دلیل نبود نصب vendor، Package پیدا نشد.
- تنظیمات PHP:
  - `max_input_vars=1000`
  - `memory_limit=128M`
  - `max_execution_time=0`
  - `post_max_size=8M`
  - `upload_max_filesize=2M`

## دستورات محیطی اجراشده

```bash
php -v
php artisan --version
composer --version
node --version
npm --version
composer show laravel/framework
composer show pestphp/pest
composer show phpunit/phpunit
php -r "echo 'max_input_vars=' . ini_get('max_input_vars') . PHP_EOL;"
php -r "echo 'memory_limit=' . ini_get('memory_limit') . PHP_EOL;"
php -r "echo 'max_execution_time=' . ini_get('max_execution_time') . PHP_EOL;"
php -r "echo 'post_max_size=' . ini_get('post_max_size') . PHP_EOL;"
php -r "echo 'upload_max_filesize=' . ini_get('upload_max_filesize') . PHP_EOL;"
```

# وضعیت Git

## دستورات ایمنی اجراشده

```bash
git branch --show-current
git status --short --branch
git diff --name-only --diff-filter=U
git ls-files -u
git log -10 --oneline
```

## نتیجه

- Branch: `work`
- وضعیت شروع Audit: بدون تغییر کاری ثبت‌شده در `git status --short --branch`.
- Conflict: خروجی `git diff --name-only --diff-filter=U` و `git ls-files -u` خالی بود.
- 10 Commit اخیر:
  - `91e361b بازطراحی خروجی محصولات — گروه‌بندی بر اساس مدل، رنگ و قیمت و دو حالت catalog/price_list`
  - `60b298d Merge pull request #719 from Abolfazlyousefii/codex/fix-typeerror-in-product-export-page`
  - `58df777 fix: support relation queries in product catalog export`
  - `3039f88 Merge pull request #718 from Abolfazlyousefii/codex/fix-migration-compatibility-for-sqlite`
  - `c99fb6c fix: make warehouse stock migrations sqlite compatible`
  - `4c7835e Merge pull request #717 from Abolfazlyousefii/codex/fix-preinvoice-migration-for-sqlite-compatibility`
  - `3a38e9b fix: make preinvoice province migration sqlite compatible`
  - `dcfed51 Merge pull request #716 from Abolfazlyousefii/codex/fix-removal-of-large-preinvoice-items`
  - `4170677 fix: submit large preinvoices with complete json payloads`
  - `5e14245 Merge pull request #715 from Abolfazlyousefii/codex/fix-product-variants-display-and-filter-redesign`

# وضعیت محیط تست

فایل‌های بررسی‌شده:

- `phpunit.xml`
- `phpunit.xml.dist`؛ وجود ندارد.
- `.env.testing`
- `tests/Pest.php`
- `tests/TestCase.php`

## نتیجه Isolation

| مورد | وضعیت |
|---|---|
| `DB_CONNECTION` تست | `sqlite` در `phpunit.xml` و `.env.testing` |
| `DB_DATABASE` تست | `:memory:` در `phpunit.xml` و `.env.testing` |
| SQLite `:memory:` | بله، به صورت force در `phpunit.xml` و پیش از Bootstrap در `tests/TestCase.php` |
| خطر اتصال تست به دیتابیس `inventory` | در سطح تنظیمات تست بسیار کم است؛ `TestCase` اگر DB واقعی یا نام `inventory` تشخیص دهد Exception می‌دهد. اما چون `vendor/autoload.php` نصب نیست، این Guard در این محیط عملاً Bootstrap نشد. |
| Queue | `sync` |
| Mail | `array`؛ ارسال واقعی انجام نمی‌شود. |
| Notification | Driver خاصی در فایل تست تعریف نشده؛ با نبود Bootstrap قابل تأیید runtime نبود. پیشنهاد: در Sprint تست، `NOTIFICATION_CHANNEL=array` یا Fakeهای استاندارد در تست‌های Feature بررسی شود. |
| Cache | `array` |
| Session | `array` |
| Config Cache تست | `bootstrap/cache/config-testing.php` |

# تست‌های اجراشده

## ساختار تست‌ها

دستور معادل لینوکسی PowerShell خواسته‌شده اجرا شد:

```bash
find tests -type f -name '*.php' -print | sort
```

- تعداد فایل‌های PHP زیر `tests`: **50**
- تعداد Test method / closure شناسایی‌شده با Scan استاتیک: **237**

## دسته‌بندی تست‌ها براساس بخش

| بخش | تعداد فایل‌های مرتبط |
|---|---:|
| Preinvoice | 10 |
| Reservation | 2 |
| Finance | 6 |
| Invoice | 14 |
| Warehouse | 2 |
| Stock | 0 |
| Discount | 3 |
| Account Statement | 2 |
| Product Export | 7 |
| Authentication | 1 |
| Architecture | 4 |

> توجه: دسته‌ها براساس نام فایل‌ها هستند و ممکن است یک فایل در چند دسته قرار بگیرد، مثل Preinvoice/Finance/Invoice.

## اجرای مرحله‌ای تست

همه اجرای‌های Artisan در مرحله Bootstrap به یک خطای محیطی واحد متوقف شدند:

```text
Failed opening required '/workspace/inventory/vendor/autoload.php'
```

| دستور | نتیجه | طبقه خطا |
|---|---|---|
| `php artisan optimize:clear` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --list-tests` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --testsuite=Unit --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=PreinvoiceLargePayload --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=PreinvoiceReservation --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=PreinvoiceDraft --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=FinancePreinvoice --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=Invoice --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=Warehouse --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |
| `php artisan test --filter=ProductCatalog --stop-on-failure` | Fail قبل از Bootstrap | A. محیط تست |

- تعداد تست واقعاً اجراشده: **0**
- تعداد Failure محیطی یکتا: **1**؛ نبود `vendor/autoload.php`
- Failure واقعی برنامه: **0 قابل اثبات**؛ چون تست‌ها اجرا نشدند.

# خطاهای محیطی

| شناسه | خطا | اثر | شدت | اقدام پیشنهادی |
|---|---|---|---|---|
| ENV-01 | `vendor/autoload.php` وجود ندارد | همه Artisan commandها و PHPUnit/Pest قبل از Bootstrap متوقف می‌شوند | Critical | نصب وابستگی‌ها در محیط CI/Dev با Cache/Proxy سالم؛ سپس اجرای دوباره همین Baseline |
| ENV-02 | `composer show` نمی‌تواند Packageهای نصب‌شده را بخواند | نسخه Laravel/Pest/PHPUnit runtime قابل تأیید نیست | High | بعد از نصب vendor، `composer show --locked` و `composer show --direct` ثبت شود |
| ENV-03 | `npm outdated` و `npm audit` با 403 Registry متوقف شدند | وضعیت قدیمی/آسیب‌پذیری Frontend قابل قضاوت قطعی نیست | Medium | تنظیم Registry/Proxy امن یا اجرای Audit در CI دارای دسترسی Registry |

# خطاهای واقعی برنامه

در این مرحله به دلیل توقف Bootstrap تست‌ها، Failure واقعی برنامه مشاهده نشد. موارد ریسک کد در بخش‌های Migration، Transaction، Query و جدول ریسک آمده‌اند و نباید به‌عنوان Bug قطعی Runtime تلقی شوند تا با تست اجراشده تأیید شوند.

# Migrationهای ناسازگار

جست‌وجو اجرا شد:

```bash
rg -n "DB::statement|information_schema|DATABASE\(\)|SHOW INDEX|MODIFY |CHANGE COLUMN| AFTER |SET FOREIGN_KEY_CHECKS|ENGINE=|COLLATE " database/migrations
```

## دسته‌بندی

### 1. SQL داخل Guard مخصوص MySQL / دارای مسیر SQLite

| فایل | خط | الگو | وضعیت |
|---|---:|---|---|
| `database/migrations/2026_02_22_070000_fix_model_lists_unique_constraints_for_branding.php` | 50 | `SHOW INDEX` | داخل `DB::getDriverName() === 'mysql'` و دارای مسیر SQLite سالم |
| `database/migrations/2026_07_08_000002_make_preinvoice_shipping_fields_nullable.php` | 65, 88, 106-107 | `MODIFY`, `information_schema`, `DATABASE()` | برای SQLite مسیر جدا دارد؛ MySQL metadata فقط در non-sqlite |
| `database/migrations/2026_06_13_150700_widen_activity_logs_action_column.php` | 20, 34 | `ALTER TABLE ... MODIFY` | برای SQLite Guard دارد و return می‌کند |
| `database/migrations/2026_05_12_162100_fix_warehouse_stocks_variant_unique_index.php` | 77-78 | `information_schema`, `DATABASE()` | Driver-specific؛ SQLite با `PRAGMA index_list` پوشش داده شده |
| `database/migrations/2026_07_11_000001_extend_stock_count_documents_for_product_stocktake.php` | 50 | `ALTER TABLE ... MODIFY` | Guard `DB::getDriverName() !== 'sqlite'` دارد |
| `database/migrations/2026_06_27_140000_change_stock_movements_reason_to_string.php` | 19, 33 | `ALTER TABLE ... MODIFY` | Guard SQLite دارد |

### 2. SQL ناسازگار با SQLite

| فایل | خط | مشکل | اثر احتمالی |
|---|---:|---|---|
| `database/migrations/2026_06_27_000001_harden_preinvoice_invoice_items.php` | 21-22 | MySQL user variables و `UPDATE ... JOIN` بدون Guard SQLite | می‌تواند migration تست SQLite را متوقف کند |
| `database/migrations/2026_06_27_000001_harden_preinvoice_invoice_items.php` | 37-38 | MySQL user variables و `UPDATE ... JOIN` بدون Guard SQLite | می‌تواند migration تست SQLite را متوقف کند |
| `database/migrations/2026_06_29_000001_add_document_date_to_sales_documents.php` | 28 | `UPDATE ... LEFT JOIN ... SET` مخصوص MySQL بدون Guard | می‌تواند migration تست SQLite را متوقف کند |
| `database/migrations/2026_03_31_101500_make_province_id_nullable_on_preinvoice_orders_table.php` | 26 | `DB::statement` در `down()`؛ SQL ساده ولی نیازمند Doctrine/change support در مسیر Down | ریسک کم برای تست معمولی؛ در rollback ممکن است اثر بگذارد |

### 3. SQLی که اجرای تست را متوقف می‌کند

- محتمل‌ترین موارد: دو Migration زیر، چون در مسیر `up()` بدون Guard SQLite هستند:
  1. `2026_06_27_000001_harden_preinvoice_invoice_items.php`
  2. `2026_06_29_000001_add_document_date_to_sales_documents.php`

### 4. SQLی که فقط در Production کاربرد دارد

- `ALTER TABLE ... MODIFY` و `information_schema`های Guard شده برای MySQL در فایل‌های بالا.

### 5. Migration سالم

- Migrationهایی که در جست‌وجوی SQL اختصاصی ظاهر نشدند، در این Audit از نظر SQL اختصاصی MySQL سالم تلقی شدند. این به معنای صحت کامل Schema نیست؛ فقط یعنی Patternهای پرخطر پیدا نشدند.

# جریان‌های بدون تست

براساس نام تست‌ها و Risk Table:

| جریان | وضعیت تست |
|---|---|
| ثبت ارسال بار | تست مستقیم اختصاصی پیدا نشد |
| Stock Count / اصلاح شمارش موجودی | تست مستقیم اختصاصی پیدا نشد |
| Product Price Change lifecycle | تست مستقیم اختصاصی پیدا نشد |
| CRM Sync و Import سفارش بیرونی | تست مستقیم اختصاصی پیدا نشد |
| Voucher/Accounting ledger کامل | تست‌های مستقیم محدود یا نامشخص |
| Product Search دارد؛ اما Product CRUD/Import کامل تست مستقیم کافی ندارد |

# جریان‌های بدون Transaction

Audit کد نشان داد اکثر جریان‌های حیاتی موجودی، پیش‌فاکتور، مالی، تبدیل فاکتور، رزرو، انبار و ارسال بار Transaction و Lock دارند. موارد مشکوک:

| جریان/فایل | خط/بخش | وضعیت | شدت | توضیح |
|---|---:|---|---|---|
| `CustomerController@store/update` | `app/Http/Controllers/CustomerController.php` حدود 62-263 | Transaction دستی دارد، اما گسترده و Controller-level است | Medium | Transaction دستی با `begin/commit/rollback` نیازمند تست مسیر Exception و تضمین عدم ارسال عملیات خارجی داخل آن است |
| Product Export | `app/Services/ProductExportService.php` | Transaction ندارد | Low | Read-only است؛ Transaction لازم نیست مگر Snapshot consistency لازم شود |
| گزارش‌ها و Audit Commands | چند فایل Console | بعضی getهای بزرگ بدون Transaction | Low/Medium | برای Commandهای read-only معمولاً لازم نیست؛ برای Repair commandها Transaction وجود دارد |

# Queryهای مشکوک

جست‌وجو اجرا شد:

```bash
rg -n -- "->get\(\)|->all\(\)|foreach|with\(|load\(|whereHas|paginate\(|chunk\(|cursor\(" app
```

| مورد | فایل/خط | شدت | دلیل | پیشنهاد اصلاح |
|---|---|---|---|---|
| Q-01 | `app/Providers/ViewServiceProvider.php:14` | Medium | `Category::orderBy('name')->get()` در View Composer می‌تواند برای هر View اجرا شود | Cache کوتاه‌مدت یا محدودکردن Composer به Layoutهای نیازمند Category |
| Q-02 | `app/Support/BugInvestigator/Rules/InvoiceRules.php:4` | Medium | داخل Loop برای هر Invoice، `preinvoiceOrder()->exists()` و Count جدا اجرا می‌شود | Eager Load/withExists/withCount برای پیشگیری از N+1 در ابزار Audit |
| Q-03 | `app/Support/BugInvestigator/Rules/ProformaWorkflowRules.php:4` | Medium | Audit روی 50 پیش‌فاکتور با Countها بهتر است، اما Ruleها هنوز ممکن است Query رابطه‌ای تولید کنند | استفاده از withExists و انتخاب ستون محدود |
| Q-04 | `app/Services/StockCountDocumentService.php:119` | High | داخل Loop آیتم‌ها در صورت نبود Stock، `lockOrCreateStock` Query جدا اجرا می‌کند | Bulk preload کامل WarehouseStockها و ساخت ردیف‌های missing به صورت کنترل‌شده |
| Q-05 | `app/Services/WarehouseCollectionService.php:155` | High | داخل Loop Variantها، اگر Variant پیدا نشود `findOrFail` جدا اجرا می‌شود | Preload همه Variantهای ورودی و Fail جمعی قبل از Transaction سنگین |
| Q-06 | `app/Services/WarehouseCollectionService.php:338` | Medium | Lock و Load هر Variant داخل Loop تغییر اقلام | Preload/lock by `whereIn` قبل از Loop و استفاده از Map |
| Q-07 | `app/Services/SalesHavalehService.php:500`, `696`, `702` | High | Lock/Query Variant/Product در مسیرهای تغییر آیتم می‌تواند با تعداد آیتم رشد کند | Lock دسته‌ای Variant/Productهای درگیر |
| Q-08 | `app/Http/Controllers/PurchaseController.php:944`, `949`, `1016`, `1022` | High | اگر Map اولیه کامل نباشد، fallback Query داخل Loop اجرا می‌شود | قبل از Loop همه `oldVariantId`/`productId`ها استخراج و Lock شوند |
| Q-09 | `app/Services/SalesReturnReportService.php:275`, `312` | Medium | `get()->concat(get())` برای union/exports ممکن است داده بزرگ را کامل Load کند | Pagination/chunk/cursor برای Exportهای بزرگ |
| Q-10 | `app/Services/Inventory/InventoryReconciliationService.php:142-154` | Medium | چند `pluck()->all()` روی aggregationهای بزرگ؛ برای کل سیستم ممکن است حافظه مصرف کند | Chunk یا محدودسازی با `productId` در UI/Commandهای عملیاتی |
| Q-11 | `app/Console/Commands/AuditMissingSalePricesCommand.php:28-61` | Low/Medium | چند Query بزرگ با `limit(500)` مناسب Audit است، ولی خروجی زیاد در حافظه | نگه‌داشتن limit و اضافه‌کردن optionهای pagination اگر داده بزرگ شد |
| Q-12 | `app/Services/ProductVariantStructureService.php:104`, `117` | Medium | برای اعتبارسنجی تنوع‌ها دو Query جدا با Relationها اجرا می‌شود | اگر در صفحات پرتکرار استفاده می‌شود Cache/Batch بررسی شود |

# N+1های احتمالی

- تعداد موارد مهم N+1 / Query رشدکننده شناسایی‌شده: **8**
- مهم‌ترین موارد: Q-02، Q-03، Q-04، Q-05، Q-06، Q-07، Q-08، Q-12.
- Product Export جدید از نظر Query روی Collectionهای Eager-loaded کار می‌کند و در این Audit Query داخل Loop واضحی در آن دیده نشد.

# وابستگی‌های Composer

دستورات اجراشده:

```bash
composer show --direct
composer outdated --direct
composer audit
```

نتیجه:

- چون `vendor` نصب نیست، `composer show --direct` و `composer outdated --direct` فقط پیام `No dependencies installed` دادند.
- `composer audit`: `No packages - skipping audit.`
- آسیب‌پذیری Composer در این محیط قابل تأیید نبود؛ نتیجه واقعی باید در CI با vendor/lock سالم اجرا شود.

## نکات Dependency از روی `composer.json`

- چند موتور PDF هم‌زمان وجود دارد: `dompdf/dompdf` و `mpdf/mpdf`؛ همچنین Export Excel با `maatwebsite/excel` وجود دارد.
- `laravel/framework`, `laravel/tinker`, `morilog/jalali`, `spatie/laravel-permission` مستقیم استفاده می‌شوند یا محتمل‌اند.
- بدون اجرای `composer show --direct` واقعی نمی‌توان Package بدون استفاده را قطعی اعلام کرد.

# وابستگی‌های NPM

دستورات اجراشده:

```bash
npm ls --depth=0
npm outdated
npm audit
```

## `npm ls --depth=0`

Packageهای مستقیم نصب‌شده:

- `@tailwindcss/forms@0.5.11`
- `@tailwindcss/vite@4.1.18`
- `alpinejs@3.15.6`
- `autoprefixer@10.4.24`
- `axios@1.13.4`
- `concurrently@9.2.1`
- `laravel-vite-plugin@2.1.0`
- `postcss@8.5.6`
- `tailwindcss@3.4.19`
- `vite@7.3.1`

## `npm outdated`

- Fail با `403 Forbidden - GET https://registry.npmjs.org/alpinejs`
- نتیجه قدیمی بودن Packageها قطعی نیست.

## `npm audit`

- Fail با `403 Forbidden - POST https://registry.npmjs.org/-/npm/v1/security/advisories/bulk`
- نتیجه آسیب‌پذیری Frontend قطعی نیست.

## نکات Frontend از Audit کد

- در `resources/views/product-exports/index.blade.php` Inline Script بزرگ وجود دارد.
- احتمال Event Listenerهای چندباره در صفحات Blade بزرگ باید جداگانه با Browser audit بررسی شود.
- Vite build موفق است و Chunk بزرگ بحرانی گزارش نشد.

# آسیب‌پذیری‌ها

| منبع | نتیجه |
|---|---|
| Composer Audit | به دلیل نبود Package نصب‌شده: `No packages - skipping audit`؛ نتیجه قطعی نیست |
| npm Audit | به دلیل 403 Registry متوقف شد؛ نتیجه قطعی نیست |

# خطاهای پرتکرار Log

مسیر بررسی شد:

```bash
find storage/logs -maxdepth 1 -type f -name '*.log'
```

نتیجه: در این محیط فایل Log قابل خواندن/موجودی زیر `storage/logs/*.log` پیدا نشد؛ بنابراین Signature خطاهای 7 روز اخیر قابل استخراج نبود.

# جدول ریسک جریان‌های حیاتی

| جریان | تست موجود | Transaction | Lock | Validation | Audit Log | ریسک | اقدام پیشنهادی |
|---|---|---|---|---|---|---|---|
| ساخت پیش‌فاکتور | بله؛ PreinvoiceLargePayload و Draft/Finance | بله در `PreinvoiceController` | بله روی Customer/Product/Variant در نقاط حساس | بله ولی Controller بزرگ است | ActivityLogger | High | تست end-to-end با داده زیاد بعد از نصب vendor اجرا و مسیرهای rollback افزوده شود |
| ذخیره پیش‌نویس | بله؛ MyPreinvoiceDraftActions | بله | بله در update draft و reservation | بله | ActivityLogger | Medium | تست همزمانی Draft reservation اضافه شود |
| ارسال به مالی | بله؛ FinancePreinvoice* | بله | بله | بله | ActivityLogger/Warehouse log | Medium | تست status transition کامل با rollback |
| تأیید مالی | بله؛ FinancePreinvoiceSaveAndFinalize/Update | بله در editor/finalize | بله | بله | ActivityLogger | High | تست Race condition تأیید همزمان |
| تبدیل به فاکتور | بله؛ FinancePreinvoiceSaveAndFinalize و Invoice | بله | بله روی Order/Invoice/Variant | بله | ActivityLogger/History | Critical | تست idempotency و unique invoice per preinvoice |
| رزرو موجودی | بله؛ PreinvoiceReservationSync و Expiry | بله | بله | بله | ActivityLogger | Critical | اجرای تست‌های Reservation پس از رفع vendor و افزودن تست concurrent |
| آزادسازی رزرو | بله محدود | بله | بله | متوسط | ActivityLog | High | تست release چندباره و expired reservations |
| جمع‌آوری انبار | بله؛ WarehouseCollection* | بله | بله | بله | History service | High | تست Query count و item edit race |
| تغییر اقلام بعد از انبار | بله؛ WarehouseCollectionItemAdjustment/LargeInvoice | بله | بله ولی Query داخل Loop محتمل | بله | History service | High | Bulk lock و تست N+1/Query count در Sprint 3 |
| ثبت ارسال بار | تست اختصاصی پیدا نشد | بله در `WarehouseShippingController` | بله روی Invoice | بله | ActivityLog | High | افزودن Feature test برای shipping, status transition, rollback |
| تخفیف ردیفی | بله؛ Discount tests | محاسبات در service؛ Transaction در caller | N/A | بله | وابسته به caller | Medium | تست ترکیب تخفیف ردیفی/کلی با برگشت مالی |
| تخفیف کلی | بله؛ Discount tests | وابسته به caller | N/A | بله | وابسته به caller | Medium | تست rounding/allocation گسترده |
| گردش حساب | بله؛ CustomerBalance/Ledger cancelled invoice | نامشخص در همه مسیرها | نامشخص | متوسط | بخشی | High | تست ledger برای پرداخت/ابطال/برگشت کالا |
| خروجی محصول | بله؛ ProductCatalog/ProductExport | Read-only؛ Transaction لازم نیست | N/A | Request دارد | N/A | Medium | تست Query count پس از اجرای واقعی و جداکردن JS بزرگ |

# برنامه اصلاح پیشنهادی

## Sprint 1: Critical Data Safety

1. **تأیید idempotency تبدیل پیش‌فاکتور به فاکتور**
   - شدت: Critical
   - فایل‌های مرتبط: `app/Http/Controllers/PreinvoiceController.php`, `app/Services/SalesHavalehService.php`, migrations invoice unique
   - ریسک تغییر: High
   - زمان تقریبی: 1-2 روز
   - تست لازم: تبدیل همزمان، تکرار Submit، rollback هنگام خطا
   - ترتیب اجرا: 1

2. **پوشش تست ثبت ارسال بار**
   - شدت: High
   - فایل‌های مرتبط: `app/Http/Controllers/WarehouseShippingController.php`
   - ریسک تغییر: Medium
   - زمان تقریبی: 0.5-1 روز
   - تست لازم: status transition، ActivityLog، rollback validation
   - ترتیب اجرا: 2

3. **تست آزادسازی رزرو و همزمانی رزرو**
   - شدت: Critical
   - فایل‌های مرتبط: `app/Services/PreinvoiceReservationService.php`, `app/Services/PreinvoiceDraftReservationService.php`
   - ریسک تغییر: High
   - زمان تقریبی: 1-2 روز
   - تست لازم: concurrent reserve/release و expired reservations
   - ترتیب اجرا: 3

## Sprint 2: Test Infrastructure

1. **رفع Bootstrap محیط تست**
   - شدت: Critical
   - فایل‌های مرتبط: `composer.lock`, CI setup, vendor cache
   - ریسک تغییر: Low
   - زمان تقریبی: 0.5 روز
   - تست لازم: `php artisan test --list-tests`
   - ترتیب اجرا: 1

2. **رفع Migrationهای MySQL-only در SQLite test**
   - شدت: High
   - فایل‌های مرتبط: `2026_06_27_000001_harden_preinvoice_invoice_items.php`, `2026_06_29_000001_add_document_date_to_sales_documents.php`
   - ریسک تغییر: Medium
   - زمان تقریبی: 1 روز
   - تست لازم: migrate روی SQLite memory و MySQL staging
   - ترتیب اجرا: 2

3. **ثبت Policy برای Notification/Mail fake در تست‌ها**
   - شدت: Medium
   - فایل‌های مرتبط: `phpunit.xml`, `tests/TestCase.php`
   - ریسک تغییر: Low
   - زمان تقریبی: 0.5 روز
   - تست لازم: TestEnvironmentIsolationTest
   - ترتیب اجرا: 3

## Sprint 3: Database and Query Performance

1. **Bulk lock در تغییر اقلام انبار/حواله**
   - شدت: High
   - فایل‌های مرتبط: `WarehouseCollectionService`, `SalesHavalehService`, `PurchaseController`
   - ریسک تغییر: High
   - زمان تقریبی: 2-3 روز
   - تست لازم: Query count و سناریوهای تغییر اقلام بزرگ
   - ترتیب اجرا: 1

2. **کاهش Queryهای View Composer عمومی**
   - شدت: Medium
   - فایل‌های مرتبط: `app/Providers/ViewServiceProvider.php`
   - ریسک تغییر: Low
   - زمان تقریبی: 0.5 روز
   - تست لازم: smoke صفحات اصلی و cache invalidation
   - ترتیب اجرا: 2

3. **Audit ابزارهای BugInvestigator برای N+1**
   - شدت: Medium
   - فایل‌های مرتبط: `app/Support/BugInvestigator/*`
   - ریسک تغییر: Low
   - زمان تقریبی: 1 روز
   - تست لازم: Unit برای Ruleها با Query count
   - ترتیب اجرا: 3

## Sprint 4: Frontend and Dependency Optimization

1. **اجرای واقعی composer/npm audit در CI دارای دسترسی شبکه**
   - شدت: High
   - فایل‌های مرتبط: `composer.lock`, `package-lock.json`
   - ریسک تغییر: Low
   - زمان تقریبی: 0.5 روز
   - تست لازم: CI audit job
   - ترتیب اجرا: 1

2. **تصمیم‌گیری درباره موتورهای PDF هم‌زمان**
   - شدت: Medium
   - فایل‌های مرتبط: `composer.json`, سرویس‌های Export/PDF
   - ریسک تغییر: Medium
   - زمان تقریبی: 1-2 روز
   - تست لازم: snapshot خروجی PDF/Print
   - ترتیب اجرا: 2

3. **خارج‌کردن Inline Scriptهای بزرگ از Blade**
   - شدت: Medium
   - فایل‌های مرتبط: `resources/views/product-exports/index.blade.php` و Bladeهای بزرگ مشابه
   - ریسک تغییر: Medium
   - زمان تقریبی: 1-2 روز
   - تست لازم: npm build، Browser smoke، Live Filter
   - ترتیب اجرا: 3

# خلاصه عددی

| شاخص | مقدار |
|---|---:|
| تست شناسایی‌شده با Scan استاتیک | 237 |
| تست واقعاً اجراشده | 0 |
| Failure محیطی یکتا | 1 |
| Failure واقعی برنامه | 0 قابل اثبات |
| Migration ناسازگار/پرریسک با SQLite | 3 مورد فایل/مسیر مهم |
| جریان Critical بدون تست | 0 قطعی؛ اما چند جریان High بدون تست مستقیم وجود دارد |
| عملیات حساس بدون Transaction | 0 مورد Critical قطعی؛ چند مورد Medium نیازمند بازبینی |
| N+1/Query رشدکننده احتمالی | 8 مورد مهم |
| Composer audit | غیرقطعی؛ `No packages - skipping audit` به دلیل نبود vendor |
| npm audit | غیرقطعی؛ 403 Registry |
| Build | موفق، حدود 4 ثانیه، CSS 40.75 kB، JS 82.26 kB، هشدار Browserslist |
