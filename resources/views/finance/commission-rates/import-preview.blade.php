@extends('layouts.app')
@section('title', 'پیش‌نمایش ورود نرخ پورسانت')
@section('page-title', 'مالی / پیش‌نمایش نرخ پورسانت')

@section('content')
@php
    $summary = $manifest['summary'] ?? [];
    $labels = [
        'ready' => ['آماده', 'success'],
        'missing_product' => ['محصول پیدا نشد', 'danger'],
        'ambiguous' => ['چند تطابق', 'warning'],
        'duplicate_product' => ['تکراری', 'warning'],
        'missing_price' => ['قیمت نامعتبر', 'danger'],
        'invalid_percentage' => ['درصد نامعتبر', 'danger'],
    ];
@endphp

<div class="container-fluid py-4" id="commissionImportPreview"
     data-apply-url="{{ route('finance.commission-rates.import.apply') }}"
     data-token="{{ $token }}">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">پیش‌نمایش ورود نرخ پورسانت</h1>
            <p class="text-muted mb-0">{{ $manifest['source_name'] ?? '' }}</p>
        </div>
        <a href="{{ route('finance.commission-rates.import.create') }}" class="btn btn-outline-secondary">انتخاب فایل دیگر</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">کل ردیف کالا</div><div class="h4 mb-0">{{ $summary['total_rows'] ?? 0 }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">نرخ اختصاصی آماده</div><div class="h4 mb-0">{{ $summary['ready_set'] ?? 0 }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">ارث‌بری آماده</div><div class="h4 mb-0">{{ $summary['ready_inherit'] ?? 0 }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">نیازمند بررسی</div><div class="h4 mb-0">{{ ($summary['missing_product'] ?? 0) + ($summary['ambiguous'] ?? 0) + ($summary['duplicate_product'] ?? 0) + ($summary['missing_price'] ?? 0) + ($summary['invalid_percentage'] ?? 0) }}</div></div></div></div>
    </div>

    <div class="alert alert-warning">
        فقط ردیف‌های «آماده» ثبت می‌شوند. ردیف‌های مبهم، پیدا نشده یا نامعتبر هیچ تغییری در دیتابیس ایجاد نمی‌کنند.
        زمان شروع همه تغییرات، لحظه تأیید نهایی خواهد بود.
    </div>

    <div id="importProgressWrap" class="d-none mb-3">
        <div class="progress mb-2" style="height: 22px;">
            <div id="importProgress" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
        </div>
        <div id="importStatus" class="small text-muted"></div>
    </div>

    <div id="importErrors" class="alert alert-danger d-none"></div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-success" id="applyImport"
            @if((($summary['ready_set'] ?? 0) + ($summary['ready_inherit'] ?? 0)) === 0) disabled @endif>
            اعمال نرخ‌های آماده
        </button>
        <a href="{{ route('finance.commission-rates.index') }}" class="btn btn-outline-secondary">انصراف</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>ردیف اکسل</th>
                        <th>دسته</th>
                        <th>نام اکسل</th>
                        <th>محصول دیتابیس</th>
                        <th>پورسانت</th>
                        <th>درصد جدید</th>
                        <th>نرخ فعلی</th>
                        <th>عملیات</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                @foreach(($manifest['rows'] ?? []) as $row)
                    @php
                        [$statusLabel, $statusColor] = $labels[$row['status'] ?? ''] ?? [$row['status'] ?? '—', 'secondary'];
                    @endphp
                    <tr>
                        <td>{{ $row['excel_row'] }}</td>
                        <td>{{ $row['category'] ?: '—' }}</td>
                        <td>{{ $row['excel_name'] }}</td>
                        <td>
                            @if($row['product_id'])
                                #{{ $row['product_id'] }} — {{ $row['product_name'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $row['commission'] === null ? 'خالی' : number_format($row['commission']) }}</td>
                        <td>{{ $row['percentage'] === null ? 'ارث‌بری' : $row['percentage'].'٪' }}</td>
                        <td>{{ $row['current_rate'] === null ? '—' : rtrim(rtrim($row['current_rate'], '0'), '.').'٪' }}</td>
                        <td>{{ ($row['action'] ?? '') === 'inherit' ? 'بازگشت به نرخ دسته' : 'ثبت نرخ اختصاصی' }}</td>
                        <td><span class="badge text-bg-{{ $statusColor }}">{{ $statusLabel }}</span><div class="small text-muted mt-1">{{ $row['message'] }}</div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var root = document.getElementById('commissionImportPreview');
    if (!root) return;

    var button = document.getElementById('applyImport');
    var progressWrap = document.getElementById('importProgressWrap');
    var progress = document.getElementById('importProgress');
    var status = document.getElementById('importStatus');
    var errorsBox = document.getElementById('importErrors');
    var url = root.dataset.applyUrl;
    var token = root.dataset.token;
    var offset = 0;
    var applied = 0;
    var inherited = 0;
    var unchanged = 0;
    var allErrors = [];

    function renderErrors() {
        if (!allErrors.length) return;
        errorsBox.classList.remove('d-none');
        errorsBox.innerHTML = '<strong>برخی ردیف‌ها ثبت نشدند:</strong><ul class="mb-0 mt-2">' +
            allErrors.map(function (e) {
                return '<li>ردیف ' + (e.excel_row || '—') + ' — ' + (e.name || '') + ': ' + (e.message || 'خطا') + '</li>';
            }).join('') + '</ul>';
    }

    async function sendBatch() {
        var body = new FormData();
        body.append('_token', '{{ csrf_token() }}');
        body.append('token', token);
        body.append('offset', offset);

        var response = await fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'Accept': 'application/json' }
        });

        var data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'ثبت نرخ‌ها ناموفق بود.');
        }

        offset = data.next_offset;
        applied += data.applied || 0;
        inherited += data.inherited || 0;
        unchanged += data.unchanged || 0;
        allErrors = allErrors.concat(data.errors || []);

        var total = Math.max(data.total || 0, 1);
        var percent = Math.min(100, Math.round((offset / total) * 100));
        progress.style.width = percent + '%';
        progress.textContent = percent + '%';
        status.textContent = 'ثبت نرخ اختصاصی: ' + applied + ' | بازگشت به ارث‌بری: ' + inherited + ' | بدون تغییر: ' + unchanged;

        if (data.done) {
            progress.classList.remove('progress-bar-animated');
            renderErrors();
            status.textContent += ' — عملیات تمام شد.';
            button.textContent = 'انجام شد';
            window.setTimeout(function () {
                window.location.href = '{{ route('finance.commission-rates.index') }}';
            }, allErrors.length ? 4000 : 1200);
            return;
        }

        await sendBatch();
    }

    button.addEventListener('click', function () {
        if (!window.confirm('نرخ‌های آماده از همین لحظه اعمال شوند؟')) return;

        button.disabled = true;
        progressWrap.classList.remove('d-none');
        errorsBox.classList.add('d-none');

        sendBatch().catch(function (error) {
            button.disabled = false;
            progress.classList.remove('progress-bar-animated');
            errorsBox.classList.remove('d-none');
            errorsBox.textContent = error.message || 'خطای غیرمنتظره رخ داد.';
        });
    });
})();
</script>
@endpush
