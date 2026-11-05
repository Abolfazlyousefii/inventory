@extends('layouts.app')
@section('title', 'سند یکپارچه تاریخی انبارگردانی')
@section('content')
<div class="container-fluid py-4 px-3" dir="rtl" style="max-width:1500px">
    <header class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div>
                    <div class="text-muted small mb-2">آرشیو انبارگردانی / ثبت دسته‌ای تاریخی</div>
                    <h3 class="mb-2">انبارگردانی {{ $group->scope_name }}</h3>
                    <p class="mb-0 text-muted">این نمایش یکپارچه مربوط به عملیات ثبت نهایی شماره {{ $group->batch_id }} است. اسناد اصلی و گردش‌های موجودی تغییر نکرده‌اند.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-primary" href="{{ route('stocktake.index') }}">بازگشت به آرشیو</a>
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">چاپ</button>
                </div>
            </div>
        </div>
    </header>

    <div class="alert alert-info">
        <strong>سند نمایشی تاریخی:</strong> این عملیات در نسخه قدیمی نرم‌افزار به {{ count($group->child_document_ids) }} سند محصولی تقسیم شده بود.
        اکنون یک گزارش سندمانند از همان شمارش‌ها می‌بینید؛ این صفحه شماره سند رسمی تازه یا تعدیل جدید موجودی ایجاد نمی‌کند.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">زیردسته / محدوده</div><strong>{{ $group->scope_name }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">تاریخ سند</div><strong>{{ \App\Support\JalaliDate::date($group->document_date) }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">انبار</div><strong>{{ $group->warehouse?->name ?? '—' }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">ثبت‌کننده</div><strong>{{ $group->creator?->name ?? '—' }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">تعداد تنوع‌ها</div><strong>{{ number_format($group->items_count) }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">جمع افزایش</div><strong class="text-success">{{ number_format($group->total_increase) }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">جمع کاهش</div><strong class="text-danger">{{ number_format($group->total_decrease) }}</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">وضعیت عملیات</div><strong>نهایی‌شده</strong></div></div></div>
    </div>

    <section class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
            <strong>تمام ردیف‌های شمارش این زیردسته</strong>
            <span class="small text-muted">{{ number_format($items->total()) }} ردیف در {{ number_format(count($group->child_document_ids)) }} سند اصلی</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr><th>محصول</th><th>تنوع / SKU</th><th>موجودی مورد انتظار هنگام شمارش</th><th>شمارش واقعی</th><th>اختلاف</th></tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td>{{ $item->product_name_snapshot ?? '—' }}</td>
                                <td>{{ $item->variant_name_snapshot ?? '—' }}<div class="text-muted small"><bdi>{{ $item->sku_snapshot ?? '—' }}</bdi></div></td>
                                <td>{{ number_format((int) $item->expected_physical_at_start) }}</td>
                                <td>{{ $item->actual_quantity === null ? 'شمارش‌نشده' : number_format((int) $item->actual_quantity) }}</td>
                                <td class="{{ $item->difference_quantity > 0 ? 'text-success' : ($item->difference_quantity < 0 ? 'text-danger' : '') }}">
                                    {{ number_format((int) $item->difference_quantity) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">ردیف شمارشی پیدا نشد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        </div>
    </section>

    <details class="card mb-4">
        <summary class="card-header" style="cursor:pointer">اسناد اصلی این ثبت ({{ count($group->child_document_ids) }} سند) ـ مشاهده سابقه حسابرسی</summary>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>شماره سند اصلی</th><th>محصول</th><th>افزایش</th><th>کاهش</th><th>مشاهده</th></tr></thead>
                    <tbody>
                        @foreach($group->child_documents as $original)
                            <tr>
                                <td><bdi>{{ $original->document_number }}</bdi></td>
                                <td>{{ $original->product?->name ?? '—' }}</td>
                                <td>{{ number_format((int) $original->total_increase) }}</td>
                                <td>{{ number_format((int) $original->total_decrease) }}</td>
                                <td><a class="btn btn-sm btn-outline-primary" href="{{ route('stock-count-documents.view', $original) }}">مشاهده سند اصلی</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </details>
</div>
@endsection
