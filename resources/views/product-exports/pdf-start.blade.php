@php
    $storeName = $meta['store_name'] ?? config('app.name', 'سامانه انبارداری');
    $exportedAt = $meta['exported_at'] ?? now()->format('Y/m/d H:i');
    $modelLists = collect($meta['model_lists'] ?? []);
    $visibleModelLists = $modelLists->take(5);
    $remainingModelListsCount = max(0, $modelLists->count() - $visibleModelLists->count());
@endphp

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        {!! $styles !!}
    </style>
</head>
<body>
<table class="report-header">
    <tr>
        <td>
            <div class="report-title">گزارش محصولات و موجودی انبار</div>
            <div class="report-subtitle">{{ $storeName }}</div>
        </td>
        <td class="report-date">
            تاریخ تهیه گزارش:
            <br>
            <span class="number">{{ $exportedAt }}</span>
        </td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td>
            <div class="meta-label">دسته‌بندی</div>
            <div class="meta-value">{{ $meta['category'] ?? 'همه دسته‌بندی‌ها' }}</div>
        </td>
        <td>
            <div class="meta-label">انبار</div>
            <div class="meta-value">{{ $meta['warehouse'] ?? 'همه انبارها' }}</div>
        </td>
        <td>
            <div class="meta-label">وضعیت موجودی</div>
            <div class="meta-value">{{ $meta['stock_status'] ?? 'همه محصولات' }}</div>
        </td>
        <td>
            <div class="meta-label">جستجو</div>
            <div class="meta-value">{{ filled($meta['search'] ?? '') ? $meta['search'] : 'بدون جستجو' }}</div>
        </td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td>
            <div class="meta-label">مدل‌لیست‌ها</div>
            <div class="meta-value">
                @if($modelLists->isNotEmpty())
                    {{ $visibleModelLists->implode('، ') }}
                    @if($remainingModelListsCount > 0)
                        و {{ number_format($remainingModelListsCount) }} مورد دیگر
                    @endif
                @else
                    همه مدل‌لیست‌ها
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="summary">
    تعداد محصولات بعد از اعمال فیلترها در فایل خروجی درج می‌شود.
    @if($skipImages ?? false)
        <br>برای جلوگیری از خطای سرور در خروجی‌های حجیم، تصاویر محصولات در این فایل حذف شده‌اند.
    @endif
</div>

<table class="products-table">
    <thead>
        <tr>
            <th width="4%">ردیف</th>
            <th width="7%">تصویر</th>
            <th width="22%">نام محصول</th>
            <th width="13%">کد کالا</th>
            <th width="13%">دسته‌بندی</th>
            <th width="9%">موجودی</th>
            <th width="17%">قیمت</th>
            <th width="9%">وضعیت</th>
        </tr>
    </thead>
    <tbody>
