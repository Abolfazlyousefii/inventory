@php
    $balanceLabel = fn ($value) => ($value > 0 ? 'بدهکار ' : ($value < 0 ? 'بستانکار ' : 'تسویه ')).\App\Support\Currency::formatRial(abs((int) $value));
    $restoreBalance = old('_balance_adjustment') === 'yes';
@endphp
@if($collapseBalanceHistory ?? false)<details class="as-adjustment-history"><summary>سوابق اصلاح مانده <span>{{ $adjustments->total() }} مورد</span></summary>@endif
<div class="card border-0 shadow-sm my-3">
    <div class="card-header bg-white">سابقه اصلاح مانده</div>
    <div class="card-body">
        @forelse($adjustments as $adjustment)
            <div class="border rounded p-3 mb-2">
                <div class="d-flex justify-content-between gap-2 flex-wrap mb-2"><strong>{{ $adjustment->user?->name ?: 'سیستم' }}</strong><span class="small text-muted">{{ \App\Support\JalaliDate::dateTime($adjustment->occurred_at) }}</span></div>
                <div class="small">قبل: {{ $balanceLabel(data_get($adjustment->properties, 'balance_before', 0)) }} | بعد: {{ $balanceLabel(data_get($adjustment->properties, 'balance_after', 0)) }}</div>
                <div class="mt-2" style="white-space:pre-wrap">{{ data_get($adjustment->properties, 'reason', '—') }}</div>
            </div>
        @empty
            <p class="text-muted mb-0">اصلاح مانده‌ای ثبت نشده است.</p>
        @endforelse
        {{ $adjustments->links() }}
    </div>
</div>
@if($collapseBalanceHistory ?? false)</details>@endif
@if($canAdjustBalance)
<div class="modal fade unified-payment-modal" id="balanceAdjustmentModal" tabindex="-1" aria-labelledby="balanceAdjustmentTitle" aria-hidden="true" dir="rtl" @if($restoreBalance && $errors->any()) data-balance-reopen @endif>
    <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="balanceAdjustmentTitle">اصلاح مانده حساب</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
        <div class="modal-body">
            <div class="payment-entry-balance mb-3"><span>مانده فعلی:</span><strong>{{ $balanceLabel($netBalance) }}</strong></div>
            <p class="small text-muted">ماندهٔ نهایی موردنظر را وارد کنید. فقط اختلاف آن با ماندهٔ فعلی با سند اصلاحی ثبت می‌شود؛ فاکتورها و پرداخت‌های قبلی تغییر نمی‌کنند.</p>
            @if($restoreBalance && $errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="POST" action="{{ route('account-statements.adjustments.store', $customer->id) }}" class="row g-3" data-balance-adjustment>
                @csrf
                <input type="hidden" name="_balance_adjustment" value="yes">
                <input type="hidden" name="expected_balance" value="{{ $restoreBalance ? old('expected_balance', $netBalance) : $netBalance }}">
                <div class="col-12"><label class="form-label">وضعیت نهایی موردنظر</label><select name="balance_type" class="form-select" required>@foreach(['debit'=>'بدهکار','credit'=>'بستانکار','settled'=>'تسویه'] as $value=>$label)<option value="{{ $value }}" @selected(($restoreBalance ? old('balance_type') : ($netBalance > 0 ? 'debit' : ($netBalance < 0 ? 'credit' : 'settled'))) === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">مبلغ نهایی مانده (ریال)</label><input name="target_amount" type="text" inputmode="numeric" autocomplete="off" class="form-control" value="{{ $restoreBalance ? old('target_amount') : abs($netBalance) }}" required></div>
                <div class="col-12"><label class="form-label">علت اصلاح (الزامی)</label><textarea name="reason" rows="3" class="form-control" minlength="10" maxlength="1000" required>{{ $restoreBalance ? old('reason') : '' }}</textarea><div class="form-text">علت، کاربر، زمان و ماندهٔ قبل و بعد در سوابق ثبت می‌شود.</div></div>
                <div class="col-12 d-flex justify-content-end gap-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-success">ثبت سند اصلاحی</button></div>
            </form>
        </div>
    </div></div>
</div>
<script src="{{ asset('js/account-balance-adjustment.js') }}?v=20261007" defer></script>
@endif
