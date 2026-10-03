@extends('layouts.app')

@section('content')
@php
    $rows = collect($manifest['rows']);
    $ready = $rows->where('status', 'ready');
    $issues = $rows->whereNotIn('status', ['ready', 'unchanged']);
    $rial = fn ($value) => $value === null ? '—' : number_format($value) . ' ریال';
@endphp
<div class="container-fluid" dir="rtl" style="max-width:1200px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">پیش‌نمایش اصلاح مانده</h4><div class="text-muted small">{{ $manifest['source_name'] }}</div></div>
        <a href="{{ route('account-statements.import.create') }}" class="btn btn-outline-secondary">بازگشت و انتخاب فایل دیگر</a>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="card"><div class="card-body">ردیف‌های آماده <strong>{{ number_format($ready->count()) }}</strong></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">نیازمند بررسی <strong>{{ number_format($issues->count()) }}</strong></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">بدون تغییر <strong>{{ number_format($rows->where('status', 'unchanged')->count()) }}</strong></div></div></div>
    </div>
    <div class="alert alert-warning">این پیش‌نمایش خودِ ثبت نیست. ماندهٔ هر مشتری هنگام ثبت دوباره کنترل می‌شود؛ اگر تغییر کرده باشد آن ردیف رد می‌شود. مبلغ‌ها ریال هستند و مثبت یعنی بدهکار، منفی یعنی بستانکار.</div>
    @if($issues->isNotEmpty())
    <div class="card mb-3"><div class="card-header">ردیف‌های ردشده یا نیازمند بررسی</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ردیف</th><th>مشتری</th><th>ماندهٔ فایل</th><th>ماندهٔ فعلی</th><th>علت</th></tr></thead><tbody>
        @foreach($issues as $row)<tr><td>{{ $row['excel_row'] }}</td><td>{{ $row['name'] }}</td><td>{{ $rial($row['expected']) }}</td><td>{{ $rial($row['current']) }}</td><td>{{ $row['message'] }}</td></tr>@endforeach
    </tbody></table></div></div>
    @endif
    @if($ready->isNotEmpty())
    <div class="card mb-3"><div class="card-header">اسناد اصلاحی آمادهٔ ثبت</div><div class="table-responsive" style="max-height:420px;overflow:auto"><table class="table table-sm table-striped mb-0"><thead class="sticky-top bg-white"><tr><th>ردیف</th><th>مشتری</th><th>موبایل</th><th>ماندهٔ فعلی</th><th>ماندهٔ هدف</th><th>اختلاف سند</th></tr></thead><tbody>
        @foreach($ready as $row)<tr><td>{{ $row['excel_row'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['mobile'] }}</td><td>{{ $rial($row['expected']) }}</td><td>{{ $rial($row['target']) }}</td><td>{{ $rial($row['target'] - $row['expected']) }}</td></tr>@endforeach
    </tbody></table></div></div>
    <div class="card mb-4"><div class="card-body">
        <label class="d-flex gap-2 align-items-start mb-3"><input id="confirmImport" type="checkbox" class="form-check-input mt-1"><span>نام مشتریان و مانده‌های هدف را بررسی کردم و ثبت {{ number_format($ready->count()) }} سند اصلاحی را تأیید می‌کنم.</span></label>
        <button id="applyImport" type="button" class="btn btn-primary" disabled>ثبت اسناد اصلاحی</button>
        <div id="importProgress" class="mt-3 small" role="status" aria-live="polite"></div>
        <div id="importErrors" class="text-danger small mt-2"></div>
    </div></div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const confirm = document.getElementById('confirmImport');
    const button = document.getElementById('applyImport');
    if (!confirm || !button) return;
    const progress = document.getElementById('importProgress');
    const errors = document.getElementById('importErrors');
    let offset = 0, applied = 0, unchanged = 0, failed = 0;
    confirm.addEventListener('change', () => button.disabled = !confirm.checked);
    button.addEventListener('click', async () => {
        button.disabled = true;
        confirm.disabled = true;
        try {
            while (true) {
                const response = await fetch(@json(route('account-statements.import.apply')), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                    body: JSON.stringify({token: @json($token), offset, confirmed: true})
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'ثبت متوقف شد.');
                applied += result.applied;
                unchanged += result.unchanged;
                failed += result.errors.length;
                for (const error of result.errors) {
                    const line = document.createElement('div');
                    line.textContent = `ردیف ${error.excel_row} ـ ${error.name}: ${error.message}`;
                    errors.appendChild(line);
                }
                offset = result.next_offset;
                progress.textContent = `بررسی ${offset} از ${result.total} ردیف؛ ثبت‌شده ${applied}، بدون تغییر ${unchanged}، ناموفق ${failed}`;
                if (result.done) { progress.textContent += ' ـ پایان عملیات'; break; }
            }
        } catch (error) {
            progress.textContent = `عملیات در ردیف ${offset + 1} متوقف شد: ${error.message} صفحه را تازه نکنید؛ برای ادامه دوباره دکمه را بزنید.`;
            button.disabled = false;
            confirm.disabled = false;
        }
    });
})();
</script>
@endpush
