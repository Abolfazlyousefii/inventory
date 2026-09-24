@extends('layouts.app')
@section('title', 'سند پورسانت ' . $document->document_number)

@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $statusMap = [
        'draft'     => ['label' => 'پیش‌نویس', 'class' => 'bg-warning text-dark'],
        'confirmed' => ['label' => 'تأیید‌شده', 'class' => 'bg-primary'],
        'finalized' => ['label' => 'نهایی‌شده', 'class' => 'bg-success'],
    ];
    $st = $statusMap[$document->status] ?? ['label' => $document->status, 'class' => 'bg-secondary'];
@endphp

@push('styles')
<style>
    .doc-show { --primary: #1a73b5; --primary-light: #e8f1fb; --bg: #f7f9fc; --border: #e3e8ef; --text-muted: #6b7b8d; }
    .doc-show { background: var(--bg); border-radius: 1rem; padding: 1.25rem; }

    /* هدر سند */
    .doc-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: .75rem; margin-bottom: 1rem; }
    .doc-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }
    .doc-subtitle { font-size: .8rem; color: var(--text-muted); margin: .15rem 0 0; }
    .doc-status { font-size: .72rem; padding: .2rem .6rem; border-radius: 1rem; font-weight: 600; vertical-align: middle; }

    /* کارت‌های KPI */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .6rem; margin-bottom: 1rem; }
    .kpi-card { background: #fff; border: 1px solid var(--border); border-radius: .6rem; padding: .6rem .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .kpi-card__label { font-size: .7rem; color: var(--text-muted); margin-bottom: .15rem; }
    .kpi-card__value { font-size: .95rem; font-weight: 700; color: #1e293b; }
    .kpi-card__value--primary { color: var(--primary); }
    .kpi-card__value--success { color: #0d7a3f; }
    .kpi-card__value--danger { color: #c0392b; }
    .kpi-card--highlight { border-color: var(--primary); background: var(--primary-light); }
    .kpi-card--warning { border-color: #e2a03f; background: #fef9ed; }

    /* جدول */
    .doc-table { font-size: .78rem; }
    .doc-table th { font-weight: 600; color: var(--text-muted); font-size: .72rem; text-transform: none; background: var(--primary-light); border: 0; white-space: nowrap; }
    .doc-table td { border-color: var(--border); vertical-align: middle; }
    .doc-table .row-missing > td { background: #fef2f2; }

    /* بخش‌های فرعی */
    .doc-section { background: #fff; border: 1px solid var(--border); border-radius: .6rem; margin-bottom: .75rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .doc-section__header { padding: .5rem .75rem; font-size: .8rem; font-weight: 600; color: #1e293b; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .doc-section__body { padding: .6rem .75rem; }

    /* دکمه‌ها */
    .doc-show .btn-sm { font-size: .75rem; padding: .2rem .5rem; }
    .doc-show .btn-primary { background: var(--primary); border-color: var(--primary); }
    .doc-show .btn-primary:hover { background: #155d94; border-color: #155d94; }
    .doc-show .btn-outline-primary { color: var(--primary); border-color: var(--primary); }
    .doc-show .btn-outline-primary:hover { background: var(--primary); color: #fff; }

    /* فوتر */
    .doc-footer { font-size: .72rem; color: var(--text-muted); padding-top: .5rem; border-top: 1px solid var(--border); }

    /* Alert */
    .doc-show .alert { font-size: .8rem; padding: .5rem .75rem; border-radius: .5rem; margin-bottom: .75rem; }
</style>
@endpush

@section('content')
<div class="container-fluid py-3">
<div class="doc-show">

    {{-- پیام‌ها --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    {{-- هدر --}}
    <div class="doc-header">
        <div>
            <h1 class="doc-title">
                {{ $document->document_number }}
                <span class="badge doc-status {{ $st['class'] }}">{{ $st['label'] }}</span>
            </h1>
            <p class="doc-subtitle">
                {{ $document->seller?->name ?? '—' }} · {{ JalaliDate::date($document->period_from) }} تا {{ JalaliDate::date($document->period_to) }}
            </p>
        </div>
        <div class="d-flex gap-1 flex-wrap">
            @if($document->isDraft())
                <a class="btn btn-outline-warning btn-sm" href="{{ route('finance.seller-sales.edit', $document) }}">ویرایش</a>
            @endif
            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="{{ route('finance.seller-sales.print', $document) }}">چاپ</a>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('finance.seller-sales.index') }}">بازگشت</a>
        </div>
    </div>

    {{-- هشدار نرخ ناموجود --}}
    @if($document->missing_rate_count > 0)
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div>
                <strong>توجه:</strong> این سند {{ number_format($document->missing_rate_count) }} آیتم بدون نرخ پورسانت دارد.
                پیش از تأیید، نرخ‌ها را تکمیل کنید.
            </div>
            @if($document->isDraft())
                <form method="POST" action="{{ route('finance.seller-sales.recalculate', $document) }}" class="ms-3">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary"
                            onclick="return confirm('نرخ‌های تمام آیتم‌ها بر اساس تنظیمات فعلی بازمحاسبه می‌شوند. ادامه می‌دهید؟')">
                        ↻ بازمحاسبه نرخ‌ها
                    </button>
                </form>
            @endif
        </div>
    @endif

    {{-- KPI --}}
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-card__label">تعداد فاکتور</div>
            <div class="kpi-card__value">{{ number_format($document->invoice_count) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-card__label">جمع فروش</div>
            <div class="kpi-card__value">{{ Currency::formatRial($document->total_sales_amount) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-card__label">پورسانت محاسبه‌شده</div>
            <div class="kpi-card__value kpi-card__value--primary">{{ Currency::formatRial($document->total_commission_amount) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-card__label">تنظیمات دستی</div>
            <div class="kpi-card__value {{ $document->total_adjustment_amount >= 0 ? 'kpi-card__value--success' : 'kpi-card__value--danger' }}">
                {{ Currency::formatRial($document->total_adjustment_amount) }}
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-card__label">بونوس تشویقی</div>
            <div class="kpi-card__value">{{ Currency::formatRial($document->bonus_amount) }}</div>
        </div>
        <div class="kpi-card kpi-card--highlight">
            <div class="kpi-card__label">پورسانت خالص</div>
            <div class="kpi-card__value kpi-card__value--primary">{{ Currency::formatRial($document->net_commission_amount) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-card__label">وجه نقد دریافتی</div>
            <div class="kpi-card__value">{{ Currency::formatRial($document->cash_collected_amount) }}</div>
        </div>
        @if($document->missing_rate_count > 0)
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__label">آیتم بدون نرخ</div>
                <div class="kpi-card__value kpi-card__value--danger">{{ number_format($document->missing_rate_count) }}</div>
            </div>
        @endif
    </div>

    {{-- جدول فاکتورها --}}
    <div class="doc-section">
        <div class="doc-section__header">ریز فاکتورها</div>
        <div class="table-responsive">
            <table class="table table-sm doc-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th><th>شماره</th><th>تاریخ</th><th>مشتری</th>
                        <th>کالا</th><th>تعداد</th><th>مبلغ</th><th>نرخ</th><th>پورسانت</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($document->items as $i => $item)
                    <tr @class(['row-missing' => $item->missing_rate])>
                        <td class="text-muted">{{ $i + 1 }}</td>
                        <td>{{ $item->invoice_number_snapshot }}</td>
                        <td>{{ JalaliDate::date($item->invoice_date_snapshot) }}</td>
                        <td>{{ $item->customer_name_snapshot }}</td>
                        <td>
                            {{ $item->product_name_snapshot }}
                            @if($item->variant_name_snapshot)
                                <small class="text-muted">({{ $item->variant_name_snapshot }})</small>
                            @endif
                        </td>
                        <td>{{ number_format($item->quantity_snapshot) }}</td>
                        <td>{{ Currency::formatRial($item->invoice_total_snapshot) }}</td>
                        <td>{{ $item->rate_snapshot }}٪</td>
                        <td>
                            @if($item->missing_rate)
                                <span class="badge bg-danger">بدون نرخ</span>
                                @if($document->isDraft() && $item->product_id)
                                    <a href="{{ route('finance.commission-rates.index', ['highlight' => $item->product_id]) }}"
                                       class="btn btn-sm btn-outline-warning ms-1" target="_blank" title="تعیین نرخ">
                                        تعیین نرخ
                                    </a>
                                @elseif($document->isDraft())
                                    <small class="text-muted d-block">چند کالا — از تنظیمات نرخ استفاده کنید</small>
                                @endif
                            @else
                                <strong class="text-success">{{ Currency::formatRial($item->commission_amount) }}</strong>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-3">آیتمی ثبت نشده.</td></tr>
                @endforelse
                </tbody>
                @if($document->items->isNotEmpty())
                    <tfoot>
                        <tr class="fw-semibold" style="background: var(--primary-light);">
                            <td colspan="6" class="text-end">جمع کل</td>
                            <td>{{ Currency::formatRial($document->total_sales_amount) }}</td>
                            <td></td>
                            <td>{{ Currency::formatRial($document->total_commission_amount) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- تنظیمات دستی --}}
    <div class="doc-section">
        <div class="doc-section__header">
            تنظیمات دستی
            @if($document->adjustments->isNotEmpty())
                <span class="text-muted" style="font-weight:400; font-size:.72rem;">جمع: {{ Currency::formatRial($document->adjustments->sum('amount')) }}</span>
            @endif
        </div>

        @if($document->adjustments->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-sm doc-table mb-0 align-middle">
                    <thead><tr><th>فاکتور</th><th>مبلغ</th><th>دلیل</th><th>ثبت‌کننده</th><th></th></tr></thead>
                    <tbody>
                    @foreach($document->adjustments as $adj)
                        <tr>
                            <td>{{ $adj->invoice?->uuid ?? '—' }}</td>
                            <td class="{{ $adj->amount >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $adj->amount >= 0 ? '+' : '' }}{{ Currency::formatRial($adj->amount) }}
                            </td>
                            <td>{{ $adj->reason }}</td>
                            <td class="text-muted">{{ $adj->creator?->name ?? '—' }}</td>
                            <td class="text-end">
                                @if($document->isDraft())
                                    <form method="POST" action="{{ route('finance.seller-sales.adjustments.destroy', [$document, $adj]) }}" onsubmit="return confirm('حذف این تنظیم؟')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="doc-section__body text-muted text-center" style="font-size:.8rem;">تنظیم دستی ثبت نشده.</div>
        @endif

        @if($document->isDraft())
            <div class="doc-section__body" style="border-top: 1px solid var(--border);">
                <form method="POST" action="{{ route('finance.seller-sales.adjustments.store', $document) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label small">فاکتور</label>
                        <select name="invoice_id" class="form-select form-select-sm" required>
                            <option value="">انتخاب…</option>
                            @foreach($document->items->unique('invoice_id') as $item)
                                <option value="{{ $item->invoice_id }}">{{ $item->invoice_number_snapshot }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">مبلغ (منفی مجاز است)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" placeholder="-500000" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">دلیل</label>
                        <input type="text" name="reason" class="form-control form-control-sm" maxlength="500" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">ثبت</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    {{-- بونوس --}}
    <div class="doc-section">
        <div class="doc-section__header">بونوس تشویقی</div>
        <div class="doc-section__body">
            @if($document->isDraft())
                <form method="POST" action="{{ route('finance.seller-sales.bonus', $document) }}" class="row g-2 align-items-end">
                    @csrf @method('PUT')
                    <div class="col-md-3">
                        <label class="form-label small">مبلغ</label>
                        <input type="number" name="bonus_amount" class="form-control form-control-sm" value="{{ $document->bonus_amount }}">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small">دلیل</label>
                        <input type="text" name="bonus_reason" class="form-control form-control-sm" maxlength="1000" value="{{ $document->bonus_reason }}">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">ثبت</button>
                    </div>
                </form>
            @else
                <div class="d-flex gap-3" style="font-size:.85rem;">
                    <div><span class="text-muted">مبلغ:</span> <strong>{{ Currency::formatRial($document->bonus_amount) }}</strong></div>
                    @if($document->bonus_reason)
                        <div><span class="text-muted">دلیل:</span> {{ $document->bonus_reason }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- یادداشت --}}
    @if($document->notes)
        <div class="doc-section">
            <div class="doc-section__header">یادداشت</div>
            <div class="doc-section__body" style="white-space:pre-line; font-size:.85rem;">{{ $document->notes }}</div>
        </div>
    @endif

    {{-- سوابق --}}
    @if($document->confirmed_at || $document->finalized_at)
        <div class="doc-section">
            <div class="doc-section__header">سوابق عملیات</div>
            <div class="doc-section__body" style="font-size:.82rem;">
                @if($document->confirmed_at)
                    <div class="mb-1">تأیید: <strong>{{ $document->confirmer?->name ?? '—' }}</strong> <span class="text-muted">— {{ JalaliDate::dateTime($document->confirmed_at) }}</span></div>
                @endif
                @if($document->finalized_at)
                    <div>نهایی‌سازی: <strong>{{ $document->finalizer?->name ?? '—' }}</strong> <span class="text-muted">— {{ JalaliDate::dateTime($document->finalized_at) }}</span></div>
                @endif
            </div>
        </div>
    @endif

    {{-- دکمه‌های عملیات --}}
    <div class="d-flex gap-2 mb-2">
        @if($document->isDraft())
            <form method="POST" action="{{ route('finance.seller-sales.confirm', $document) }}" onsubmit="return confirm('پس از تأیید ویرایش ممکن نیست. ادامه؟')">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">تأیید سند</button>
            </form>
        @endif
        @if($document->isConfirmed())
            <form method="POST" action="{{ route('finance.seller-sales.finalize', $document) }}" onsubmit="return confirm('پس از نهایی‌سازی هیچ تغییری ممکن نیست. ادامه؟')">
                @csrf
                <button type="submit" class="btn btn-success btn-sm">نهایی‌سازی</button>
            </form>
        @endif
    </div>

    <div class="doc-footer">
        ثبت‌کننده: {{ $document->creator?->name ?? '—' }} · تاریخ: {{ JalaliDate::date($document->created_at) }}
    </div>

</div>{{-- /.doc-show --}}
</div>
@endsection
