ARIA GOSTAR - Product Visit Export Patch
=======================================

هدف:
- اضافه شدن دو حالت خروجی: ویزیتوری / کاتالوگی
- حالت پیش‌فرض: ویزیتوری
- هر تنوع فعال در خروجی ویزیتوری یک ردیف مستقل دارد
- نمایش مدل/تنوع، رنگ/طرح، کد تنوع، موجودی و قیمت دقیق
- قیمت تنوع در صورت نبود sell_price از قیمت محصول مادر fallback می‌شود
- فیلتر موجودی در حالت ویزیتوری در سطح خود تنوع اعمال می‌شود
- خروجی کاتالوگی قبلی حفظ شده است
- هیچ Migration یا تغییر دیتابیس لازم نیست

نصب:
1) قبل از جایگزینی commit/backup بگیرید.
2) محتوای این ZIP را از ریشه پروژه Laravel جایگزین کنید.
3) اجرا کنید:
   php artisan optimize:clear

تست‌های پیشنهادی:
   php artisan test --filter=ProductExportFilterRequestTest --colors=never
   php artisan test --filter=ProductPriceListPdfTest --colors=never
   php artisan test --filter=ProductVisitPriceListTest --colors=never
   php artisan test --filter=ProductCatalogExportTest --colors=never
   php artisan test --filter=ProductCatalogVariantRegressionTest --colors=never
   php artisan test --filter=ProductExportProductSelectionTest --colors=never

سپس در صورت سبز بودن تست‌های هدفمند:
   php artisan test --colors=never

نکته:
- برای حفظ رفتار فعلی سامانه، فقط is_active روی تنوع‌ها اعمال می‌شود و sales_enabled در این نسخه باعث حذف تنوع از خروجی نمی‌شود.
- موجودی نمایش‌داده‌شده همان product_variants.stock است.
