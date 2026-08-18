# موتور محاسبه پورسانت — فاز ۲

## جریان محاسبه

برای هر دوره باز یا در حال بررسی، سرویس محاسبه دوره را قفل می‌کند، فاکتورهای بازه نیمه‌باز `[start_at, end_at)` را با تاریخ رسمی `document_date ?? created_at` می‌خواند و فروشنده را فقط از قرارداد `effective_seller_id` تعیین می‌کند. فاکتور لغوشده Ledger فعال ندارد و فاکتور فاقد فروشنده با هشدار `missing_seller` در Audit دوره باقی می‌ماند.

هر ردیف فاکتور جداگانه محاسبه می‌شود. مبلغ ناخالص و تخفیف ردیف و فاکتور از `SalesReturnCalculationService::invoiceItemBreakdowns()` و در نتیجه از `SalesDocumentTotals` می‌آید. Shipping هرگز Commissionable نیست. نرخ و کمپین با تاریخ خود فاکتور از Resolverهای فاز ۱ گرفته می‌شوند.

## Ledger و Recalculation

`commission_ledger_entries` حقیقت محاسباتی و مستقل از سند پرداخت است. Snapshotهای کالا، فروشنده، مبلغ، نرخ، کمپین و منبع نرخ را نگه می‌دارد. در هر لحظه برای هر `period + invoice_item` فقط یک Entry فعال مجاز است. محاسبه تغییرنکرده Entry تازه نمی‌سازد؛ محاسبه تغییرکرده Entry قبلی را `superseded` و Revision تازه را `active` می‌کند.

Invoice، InvoiceItem، تغییر نرخ و تغییر کمپین فقط دوره‌های `open/review` را dirty می‌کنند. Recalculate دوره‌های `closed/paid` رد می‌شود. Ledger هیچ Invoice را رزرو، قفل، تأیید یا پرداخت‌شده تلقی نمی‌کند.

## مرز فاز ۳

Commission Document، تصمیم مالی Approve/Reject، Add/Remove Invoice، Invoice خارج از دوره، چاپ، Settlement و Paid خارج از این فاز هستند. فاز ۳ باید روی Ledger فعال و Snapshotهای آن بنا شود و وضعیت سند را وارد Ledger محاسباتی نکند.
