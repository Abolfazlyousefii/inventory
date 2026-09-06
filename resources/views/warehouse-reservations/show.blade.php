@extends('layouts.app')

@section('title', 'جزئیات رزرو موجودی #'.$reservation->id)
@section('page-title', 'جزئیات رزرو موجودی')

@php use App\Support\JalaliDate; @endphp

@push('styles')
<style>
    .reservation-detail-page { --reservation-primary: #0f766e; }
    .reservation-detail-page .detail-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, .06); }
    .reservation-detail-page .detail-card .card-header { background: #fff; border-bottom: 1px solid #eef2f6; font-weight: 700; }
    .reservation-detail-page dl.detail-list { margin: 0; }
    .reservation-detail-page dl.detail-list dt { color: #64748b; font-size: .78rem; font-weight: 600; }
    .reservation-detail-page dl.detail-list dd { color: #0f172a; font-size: .9rem; margin-bottom: .75rem; }
    .reservation-detail-page .status-badge { border-radius: 999px; display: inline-flex; font-size: .76rem; font-weight: 700; padding: .38rem .65rem; }
    .reservation-detail-page .status-active { background: #dcfce7; color: #166534; }
    .reservation-detail-page .status-preinvoice { background: #dbeafe; color: #1d4ed8; }
    .reservation-detail-page .status-review { background: #fef3c7; color: #92400e; }
    .reservation-detail-page .status-critical { background: #fee2e2; color: #991b1b; }
    .reservation-detail-page .status-neutral { background: #e2e8f0; color: #475569; }
    .reservation-detail-page .muted-line { color: #64748b; font-size: .78rem; }
    .reservation-detail-page .timeline { list-style: none; margin: 0; padding: 0; position: relative; }
    .reservation-detail-page .timeline li { border-right: 2px solid #e2e8f0; padding: 0 1.25rem .25rem 0; position: relative; margin-bottom: 1rem; }
    .reservation-detail-page .timeline li:last-child { border-color: transparent; margin-bottom: 0; }
    .reservation-detail-page .timeline li::before { content: ''; position: absolute; right: -6px; top: 2px; width: 10px; height: 10px; border-radius: 50%; background: var(--reservation-primary); }
    .reservation-detail-page .timeline-title { color: #0f172a; font-weight: 700; font-size: .88rem; }
    .reservation-detail-page .timeline-time { color: #64748b; font-size: .76rem; }
    .reservation-detail-page .empty-note { color: #64748b; font-size: .84rem; }
</style>
@endpush

@section('content')
<div class="container-fluid py-3 reservation-detail-page" dir="rtl">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <h2 class="h4 fw-bold mb-0">رزرو موجودی #{{ $reservation->id }}</h2>
                <span class="status-badge status-neutral">{{ $classificationService->typeLabels()[$classification['type']] }}</span>
                @php
                    $labelClass = match($classification['label']) {
                        'critical', 'legacy_candidate' => 'status-critical',
                        'temporary_orphan' => 'status-review',
                        'consumed' => 'status-neutral',
                        default => 'status-active',
                    };
                    $healthClass = match($classification['health']) {
                        'critical' => 'status-critical',
                        'warning' => 'status-review',
                        default => 'status-active',
                    };
                @endphp
                <span class="status-badge {{ $labelClass }}">{{ $classificationService->managementLabels()[$classification['label']] }}</span>
                <span class="status-badge {{ $healthClass }}">سلامت: {{ $classificationService->healthLabels()[$classification['health']] }}</span>
            </div>
            <div class="muted-line" dir="ltr">{{ $reservation->token }}</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('warehouse-reservations.index') }}">بازگشت به مدیریت رزروها</a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card detail-card h-100">
                <div class="card-header">اطلاعات کالا</div>
                <div class="card-body">
                    <dl class="detail-list row">
                        <dt class="col-5">نام کالا</dt>
                        <dd class="col-7">{{ $reservation->product?->name ?? 'کالای نامشخص' }}</dd>
                        <dt class="col-5">شناسه کالا</dt>
                        <dd class="col-7">{{ $reservation->product?->id ?? '—' }}</dd>
                        <dt class="col-5">نام تنوع</dt>
                        <dd class="col-7">{{ $reservation->variant?->variant_name ?: $reservation->variant?->variety_name ?: 'بدون عنوان تنوع' }}</dd>
                        <dt class="col-5">کد تنوع / SKU</dt>
                        <dd class="col-7" dir="ltr">{{ $reservation->variant?->variant_code ?: $reservation->variant?->variety_code ?: ($reservation->product?->sku ?: '—') }}</dd>
                        <dt class="col-5">تعداد این ردیف رزرو</dt>
                        <dd class="col-7 fw-bold">{{ number_format($reservation->quantity) }}</dd>
                        <dt class="col-5">مقدار رزرو فعال این تنوع</dt>
                        <dd class="col-7">
                            <span class="fw-bold">{{ number_format($activeReservedQuantity) }}</span>
                            <div class="muted-line">جمع رزروهای فعال این تنوع در کل سامانه (متفاوت از تعداد این ردیف رزرو)</div>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card detail-card h-100">
                <div class="card-header">اطلاعات رزرو</div>
                <div class="card-body">
                    <dl class="detail-list row">
                        <dt class="col-5">شناسه رزرو</dt>
                        <dd class="col-7">{{ $reservation->id }}</dd>
                        <dt class="col-5">مرجع رزرو (Token)</dt>
                        <dd class="col-7 text-break" dir="ltr">{{ $reservation->token }}</dd>
                        <dt class="col-5">دامنه رزرو</dt>
                        <dd class="col-7">{{ $reservation->reservation_scope ?? 'ثبت نشده' }}</dd>
                        <dt class="col-5">سطح رزرو</dt>
                        <dd class="col-7">{{ $reservation->reservation_tier ?? 'ثبت نشده' }}</dd>
                        <dt class="col-5">تعداد</dt>
                        <dd class="col-7 fw-bold">{{ number_format($reservation->quantity) }}</dd>
                        <dt class="col-5">زمان ایجاد</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->created_at, 'ثبت نشده') }}</dd>
                        <dt class="col-5">آخرین heartbeat</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->last_seen_at, 'ثبت نشده') }}</dd>
                        <dt class="col-5">زمان انقضا</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->expires_at, 'ثبت نشده') }}</dd>
                        <dt class="col-5">سن رزرو</dt>
                        <dd class="col-7">{{ $reservation->managementAgeLabel() }}</dd>
                        <dt class="col-5">زمان آزادسازی</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->released_at, 'آزاد نشده') }}</dd>
                        <dt class="col-5">دلیل آزادسازی</dt>
                        <dd class="col-7">{{ $reservation->release_reason ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card detail-card h-100">
                <div class="card-header">مشتری / پیش‌فاکتور</div>
                <div class="card-body">
                    @if($reservation->order)
                        <dl class="detail-list row">
                            <dt class="col-5">فروشنده ثبت‌کننده</dt>
                            <dd class="col-7">{{ $reservation->user?->name ?? 'نامشخص' }}</dd>
                            <dt class="col-5">مشتری</dt>
                            <dd class="col-7">{{ $reservation->order->customer?->name ?? $reservation->order->customer_name ?? 'بدون مشتری مرتبط' }}</dd>
                            <dt class="col-5">موبایل مشتری</dt>
                            <dd class="col-7" dir="ltr">{{ $reservation->order->customer?->mobile ?? $reservation->order->customer_mobile ?? '—' }}</dd>
                            <dt class="col-5">شماره پیش‌فاکتور</dt>
                            <dd class="col-7" dir="ltr">{{ $reservation->order->uuid ?? '—' }}</dd>
                            <dt class="col-5">شناسه پیش‌فاکتور</dt>
                            <dd class="col-7">{{ $reservation->preinvoice_order_id }}</dd>
                            <dt class="col-5">وضعیت پیش‌فاکتور</dt>
                            <dd class="col-7">{{ $reservation->order->status ?? '—' }}</dd>
                            <dt class="col-5">فاکتور</dt>
                            <dd class="col-7">
                                @if($reservation->order->invoice)
                                    شناسه {{ $reservation->order->invoice->id }} <span dir="ltr">({{ $reservation->order->invoice->uuid }})</span>
                                @else
                                    فاکتوری صادر نشده است
                                @endif
                            </dd>
                        </dl>
                    @else
                        <p class="empty-note mb-2">بدون مشتری مرتبط — این یک رزرو موقت بدون پیش‌فاکتور است.</p>
                        <dl class="detail-list row mb-0">
                            <dt class="col-5">فروشنده / ایجادکننده</dt>
                            <dd class="col-7">{{ $reservation->user?->name ?? 'نامشخص' }}</dd>
                        </dl>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card detail-card h-100">
                <div class="card-header">طبقه‌بندی</div>
                <div class="card-body">
                    <dl class="detail-list row mb-0">
                        <dt class="col-5">نوع</dt>
                        <dd class="col-7">{{ $classificationService->typeLabels()[$classification['type']] }}</dd>
                        <dt class="col-5">چرخه</dt>
                        <dd class="col-7">{{ $classificationService->lifecycleLabels()[$classification['lifecycle']] }}</dd>
                        <dt class="col-5">سلامت</dt>
                        <dd class="col-7">{{ $classificationService->healthLabels()[$classification['health']] }}</dd>
                        <dt class="col-5">وضعیت مدیریتی</dt>
                        <dd class="col-7">{{ $classificationService->managementLabels()[$classification['label']] }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    @php
        $timelineEvents = [];
        if ($reservation->created_at) {
            $timelineEvents[] = ['title' => 'ایجاد رزرو', 'at' => $reservation->created_at];
        }
        if ($reservation->last_seen_at) {
            $timelineEvents[] = ['title' => 'دریافت آخرین heartbeat', 'at' => $reservation->last_seen_at];
        }
        if ($reservation->preinvoice_order_id !== null && $reservation->converted_at) {
            $timelineEvents[] = ['title' => 'اتصال/تبدیل به پیش‌فاکتور', 'at' => $reservation->converted_at];
        } elseif ($reservation->preinvoice_order_id !== null && $reservation->order?->created_at) {
            // order.created_at is the preinvoice order's own creation time, not
            // proof of exactly when this reservation row connected to it (no
            // such timestamp is recorded) — label it as what it actually is.
            $timelineEvents[] = ['title' => 'ایجاد پیش‌فاکتور مرتبط', 'at' => $reservation->order->created_at];
        }
        if ($reservation->order?->invoice?->created_at) {
            $timelineEvents[] = ['title' => 'صدور فاکتور', 'at' => $reservation->order->invoice->created_at];
        }
        if ($reservation->released_at) {
            $timelineEvents[] = ['title' => 'آزادسازی رزرو', 'at' => $reservation->released_at];
        }
        usort($timelineEvents, fn ($a, $b) => $a['at'] <=> $b['at']);
    @endphp
    <div class="card detail-card mt-3">
        <div class="card-header">جدول زمانی رزرو</div>
        <div class="card-body">
            @if(empty($timelineEvents))
                <p class="empty-note mb-0">داده قابل‌اعتمادی برای نمایش جدول زمانی موجود نیست.</p>
            @else
                <ul class="timeline">
                    @foreach($timelineEvents as $event)
                        <li>
                            <div class="timeline-title">{{ $event['title'] }}</div>
                            <div class="timeline-time" dir="ltr">{{ JalaliDate::dateTime($event['at']) }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card detail-card mt-3">
        <div class="card-header">تاریخچه فعالیت‌ها</div>
        <div class="card-body">
            @if($activityLogs->isEmpty())
                <p class="empty-note mb-0">فعالیت ثبت‌شده‌ای برای این رزرو یافت نشد.</p>
            @else
                <ul class="timeline mb-0">
                    @foreach($activityLogs as $log)
                        <li>
                            <div class="timeline-title">{{ $log->description }}</div>
                            <div class="timeline-time" dir="ltr">{{ JalaliDate::dateTime($log->occurred_at) }}</div>
                            <div class="muted-line">توسط: {{ $log->user?->name ?? 'سیستم' }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
