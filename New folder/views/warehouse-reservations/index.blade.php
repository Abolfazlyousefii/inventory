@extends('layouts.app')

@section('title', 'ردیابی رزروهای موجودی')

@section('content')
<style>
    .reservation-tracker { overflow-x: hidden; }
    .reservation-tracker .text-truncate-rtl { display: block; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .reservation-tracker .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; align-items: end; }
    .reservation-tracker .actions-wrap { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end; }
    .reservation-tracker table { table-layout: fixed; min-width: 860px; }
    .reservation-tracker th, .reservation-tracker td { vertical-align: middle; }
</style>

<div class="container-fluid reservation-tracker" dir="rtl">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">ردیابی رزروهای موجودی</h1>
            <div class="text-muted small">نمایش محل درگیری موجودی‌های رزروشده؛ آزادسازی فقط برای رزرو موقت draft مجاز است.</div>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3 mb-3">
        @foreach([
            'کل رزروهای فعال' => $stats['total_active'],
            'رزروهای موقت فعال' => $stats['draft_active'],
            'موقت بالای ۲۰ ساعت' => $stats['draft_over_20h'],
            'رزروهای پیش‌فاکتور فعال' => $stats['preinvoice_active'],
            'مشکوک قابل آزادسازی' => $stats['suspicious_releasable'],
        ] as $label => $value)
            <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small text-truncate">{{ $label }}</div><div class="fs-4 fw-bold">{{ number_format($value) }}</div></div></div></div>
        @endforeach
    </div>

    <form class="card border-0 shadow-sm mb-3" method="GET" action="{{ route('warehouse.reservations.index') }}">
        <div class="card-body">
            <div class="filters-grid">
                <div><label class="form-label">جستجوی کالا / کد / تنوع</label><input name="q" value="{{ $filters['q'] ?? '' }}" class="form-control"></div>
                <div><label class="form-label">ثبت‌کننده</label><select name="user_id" class="form-select"><option value="">همه</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
                <div><label class="form-label">مشتری</label><select name="customer_id" class="form-select"><option value="">همه</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(($filters['customer_id'] ?? '') == $customer->id)>{{ $customer->display_name ?: $customer->mobile }}</option>@endforeach</select></div>
                <div><label class="form-label">نوع رزرو</label><select name="type" class="form-select"><option value="">همه</option><option value="draft_reservation" @selected(($filters['type'] ?? '')==='draft_reservation')>رزرو موقت</option><option value="preinvoice_reservation" @selected(($filters['type'] ?? '')==='preinvoice_reservation')>رزرو پیش‌فاکتور</option></select></div>
                <div><label class="form-label">از تاریخ</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control"></div>
                <div><label class="form-label">تا تاریخ</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control"></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="older_than_20" value="1" @checked(!empty($filters['older_than_20'])) id="old20"><label class="form-check-label" for="old20">فقط بالای ۲۰ ساعت</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="releasable_only" value="1" @checked(!empty($filters['releasable_only'])) id="rel"><label class="form-check-label" for="rel">فقط قابل آزادسازی</label></div>
            </div>
            <div class="d-flex flex-wrap justify-content-end gap-2 mt-3"><button class="btn btn-primary">اعمال فیلتر</button><a href="{{ route('warehouse.reservations.index') }}" class="btn btn-outline-secondary">پاک کردن</a></div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th style="width: 22%">کالا</th><th style="width: 16%">تنوع / کد</th><th style="width: 7%">تعداد</th><th style="width: 12%">نوع رزرو</th><th style="width: 10%">فروشنده</th><th style="width: 10%">مشتری</th><th style="width: 10%">پیش‌فاکتور / مدت</th><th style="width: 7%">وضعیت</th><th style="width: 6%">عملیات</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td><span class="fw-bold text-truncate-rtl" title="{{ $row['product'] }}">{{ $row['product'] }}</span></td>
                        <td><span class="small text-muted text-truncate-rtl" title="{{ $row['variant'] }} | {{ $row['sku'] }}">{{ $row['variant'] }} | {{ $row['sku'] }}</span></td>
                        <td>{{ $row['quantity'] }}</td>
                        <td><span class="text-truncate-rtl" title="{{ $row['type_label'] }}">{{ $row['type_label'] }}</span></td>
                        <td><span class="text-truncate-rtl" title="{{ $row['user'] }}">{{ $row['user'] }}</span></td>
                        <td><span class="text-truncate-rtl" title="{{ $row['customer'] }}">{{ $row['customer'] }}</span></td>
                        <td><span class="text-truncate-rtl" title="{{ $row['document_no'] }}">{{ $row['document_no'] }}</span><span class="small text-muted">{{ $row['age_hours'] }} ساعت</span></td>
                        <td>@if($row['alert']==='red')<span class="badge bg-danger">قرمز</span>@elseif($row['alert']==='yellow')<span class="badge bg-warning text-dark">زرد</span>@else<span class="badge bg-success">عادی</span>@endif @unless($row['releasable'])<span class="badge bg-secondary mt-1">متصل به سند</span>@endunless</td>
                        <td><div class="actions-wrap">@if($row['releasable'] && $row['reservation']) @canPermission('warehouse.reservations.release')<button class="btn btn-sm btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#release-{{ $row['source_id'] }}">آزادسازی رزرو</button>@endcanPermission <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#details-{{ $row['source_id'] }}">جزئیات</button>@else @if($row['document_url'])<a class="btn btn-sm btn-outline-secondary" href="{{ $row['document_url'] }}">مشاهده</a>@endif @endif</div></td>
                    </tr>
                    <tr class="collapse" id="details-{{ $row['source_id'] }}"><td colspan="9" class="bg-light small"><div class="d-flex flex-wrap gap-3"><span>شماره سند: {{ $row['document_no'] }}</span><span>وضعیت سند: {{ $row['document_status'] }}</span><span>زمان ایجاد: {{ $row['created_at']->format('Y-m-d H:i') }}</span>@if($row['reservation'])<span>توکن: <span dir="ltr">{{ $row['reservation']->token }}</span></span><span>expires_at: {{ $row['reservation']->expires_at?->format('Y-m-d H:i') ?? '—' }}</span>@else<span class="text-muted">از مسیر سند قابل اصلاح است.</span>@endif</div></td></tr>
                @empty <tr><td colspan="9" class="text-center text-muted py-4">رزروی یافت نشد.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex justify-content-center">{{ $rows->links() }}</div>
    </div>

    @foreach($rows as $row)
        @if($row['releasable'] && $row['reservation'])
            <div class="modal fade" id="release-{{ $row['source_id'] }}" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('warehouse.reservations.draft.release', $row['reservation']) }}">@csrf<div class="modal-header"><h5 class="modal-title">آزادسازی رزرو موقت</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">دلیل آزادسازی</label><select name="release_reason" class="form-select" required>@foreach(['کاربر پیش‌فاکتور را ثبت نکرده','رزرو اشتباه ایجاد شده','مشتری منصرف شده','اصلاح موجودی','سایر'] as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach</select><label class="form-label mt-3">توضیحات</label><textarea name="release_note" class="form-control" rows="3"></textarea></div><div class="modal-footer"><button class="btn btn-danger">آزادسازی امن</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button></div></form></div></div>
        @endif
    @endforeach
</div>
@endsection
