@extends('layouts.app')
@section('title', $document ? 'ویرایش سند پورسانت اداری' : 'صدور سند پورسانت اداری')
@section('page-title', 'مالی / پورسانت اداری')

@section('content')
@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $isEdit = (bool) $document;
    $existing = $isEdit
        ? $document->allocations->keyBy('department_id')
        : collect();
    $prefillFrom = old('period_from', $isEdit ? JalaliDate::date($document->period_from, '') : '');
    $prefillTo = old('period_to', $isEdit ? JalaliDate::date($document->period_to, '') : '');
    $initialTotal = $isEdit ? (int) $document->total_seller_commission : 0;
@endphp

<div class="container-fluid py-4" id="orgCommissionForm"
     data-seller-total-url="{{ route('finance.org-commission.seller-total') }}"
     data-initial-total="{{ $initialTotal }}">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $isEdit ? 'ویرایش سند پورسانت اداری' : 'صدور سند پورسانت اداری' }}</h1>
            <p class="text-muted mb-0">تخصیص درصدی از کل پورسانت فروشندگان به واحدهای سازمانی</p>
        </div>
        <a href="{{ route('finance.org-commission.index', ['tab' => 'documents']) }}" class="btn btn-outline-secondary">بازگشت</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if($departments->isEmpty())
        <div class="alert alert-warning">
            هیچ واحد فعالی تعریف نشده است. ابتدا از
            <a href="{{ route('finance.org-commission.index') }}" class="alert-link">تب واحدهای سازمانی</a>
            یک واحد بسازید.
        </div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('finance.org-commission.update', $document) : route('finance.org-commission.store') }}" id="orgDocumentForm">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">بازه سند</div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="periodFrom">از تاریخ</label>
                        <input class="form-control" id="periodFrom" name="period_from" data-jdp autocomplete="off" value="{{ $prefillFrom }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="periodTo">تا تاریخ</label>
                        <input class="form-control" id="periodTo" name="period_to" data-jdp autocomplete="off" value="{{ $prefillTo }}" required>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-primary w-100" id="calculateTotalButton">محاسبه کل پورسانت</button>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-2 text-center">
                            <span class="d-block text-muted small">کل پورسانت فروشندگان در این بازه</span>
                            <strong id="sellerTotalDisplay">{{ Currency::formatRial($initialTotal) }}</strong>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="documentNotes">یادداشت</label>
                        <textarea class="form-control" id="documentNotes" name="notes" rows="2" maxlength="2000">{{ old('notes', $isEdit ? $document->notes : '') }}</textarea>
                    </div>
                </div>
                <p class="text-muted small mb-0 mt-2">مبنای محاسبه، مجموع پورسانت خالص اسناد فروشنده تأیید‌شده و نهایی‌شده‌ای است که بازه‌شان داخل این بازه قرار می‌گیرد.</p>
            </div>
        </div>

        @foreach($departments as $index => $department)
            @php
                $allocation = $existing->get($department->id);
                $shares = $allocation ? $allocation->memberShares->keyBy('member_id') : collect();
                $oldAllocation = old("allocations.$index");
            @endphp
            <div class="card border-0 shadow-sm mb-3 allocation-card" data-index="{{ $index }}">
                <input type="hidden" name="allocations[{{ $index }}][department_id]" value="{{ $department->id }}">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-bold">{{ $department->name }}</span>
                    <span class="text-muted small">{{ $department->activeMembers->count() }} عضو فعال</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label" for="percentage{{ $index }}">درصد تخصیص</label>
                            <div class="input-group">
                                <input type="number" class="form-control allocation-percentage" id="percentage{{ $index }}"
                                       name="allocations[{{ $index }}][percentage]" min="0" max="100" step="0.0001"
                                       value="{{ $oldAllocation['percentage'] ?? ($allocation ? rtrim(rtrim($allocation->percentage, '0'), '.') : '0') }}">
                                <span class="input-group-text">٪</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <span class="d-block text-muted small">مبلغ تخصیص‌یافته</span>
                            <strong class="allocation-amount" data-amount="{{ $allocation->allocated_amount ?? 0 }}">{{ Currency::formatRial($allocation->allocated_amount ?? 0) }}</strong>
                        </div>
                        <div class="col-md-5 text-md-end">
                            @if($department->activeMembers->isNotEmpty())
                                <button type="button" class="btn btn-sm btn-outline-secondary split-equally">تقسیم مساوی بین اعضا</button>
                            @endif
                        </div>
                    </div>

                    @if($department->activeMembers->isEmpty())
                        <div class="alert alert-warning mb-0 py-2 small">این واحد عضو فعالی ندارد؛ مبلغ تخصیص بدون تقسیم بین اعضا ثبت می‌شود.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-2">
                                <thead class="table-light">
                                    <tr><th>عضو</th><th>سمت</th><th style="width:22%">مبلغ سهم (ریال)</th><th style="width:30%">یادداشت</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($department->activeMembers as $memberIndex => $member)
                                        @php
                                            $share = $shares->get($member->id);
                                            $oldShare = old("allocations.$index.members.$memberIndex");
                                        @endphp
                                        <tr>
                                            <td>
                                                {{ $member->name }}
                                                <input type="hidden" name="allocations[{{ $index }}][members][{{ $memberIndex }}][member_id]" value="{{ $member->id }}">
                                            </td>
                                            <td class="text-muted small">{{ $member->role ?: '—' }}</td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm member-share" min="0" step="1"
                                                       name="allocations[{{ $index }}][members][{{ $memberIndex }}][share_amount]"
                                                       value="{{ $oldShare['share_amount'] ?? ($share->share_amount ?? 0) }}">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" maxlength="500"
                                                       name="allocations[{{ $index }}][members][{{ $memberIndex }}][notes]"
                                                       value="{{ $oldShare['notes'] ?? ($share->notes ?? '') }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="small mb-0">
                            جمع سهم اعضا: <strong class="member-share-total">۰</strong>
                            <span class="share-mismatch text-danger d-none">— با مبلغ تخصیص واحد برابر نیست.</span>
                        </p>
                    @endif
                </div>
            </div>
        @endforeach

        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <span class="d-block text-muted small">جمع درصد تخصیص</span>
                    <strong id="percentageTotal">۰٪</strong>
                    <span id="percentageWarning" class="text-danger small d-none">جمع درصدها بیش از ۱۰۰٪ است.</span>
                </div>
                <div>
                    <span class="d-block text-muted small">جمع مبلغ تخصیص</span>
                    <strong id="allocationTotal">{{ Currency::formatRial(0) }}</strong>
                </div>
                <button type="submit" class="btn btn-primary px-4" @disabled($departments->isEmpty())>{{ $isEdit ? 'ذخیره تغییرات' : 'ثبت سند' }}</button>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var $app = $('#orgCommissionForm');
    if (! $app.length) { return; }

    var sellerTotalUrl = $app.data('seller-total-url');
    var sellerTotal = parseInt($app.data('initial-total'), 10) || 0;

    function formatRial(amount) {
        return (parseInt(amount, 10) || 0).toLocaleString('fa-IR') + ' ریال';
    }

    function recalculate() {
        var percentageSum = 0;
        var allocationSum = 0;

        $('.allocation-card').each(function () {
            var $card = $(this);
            var percentage = parseFloat($card.find('.allocation-percentage').val()) || 0;
            if (percentage < 0) { percentage = 0; }

            var amount = Math.round(sellerTotal * percentage / 100);
            percentageSum += percentage;
            allocationSum += amount;

            $card.find('.allocation-amount').data('amount', amount).text(formatRial(amount));

            var $shares = $card.find('.member-share');
            if ($shares.length) {
                var shareSum = 0;
                $shares.each(function () { shareSum += parseInt($(this).val(), 10) || 0; });
                $card.find('.member-share-total').text(formatRial(shareSum));
                $card.find('.share-mismatch').toggleClass('d-none', shareSum === amount);
            }
        });

        $('#percentageTotal').text(percentageSum.toLocaleString('fa-IR') + '٪');
        $('#allocationTotal').text(formatRial(allocationSum));
        $('#percentageWarning').toggleClass('d-none', percentageSum <= 100);
    }

    $('#calculateTotalButton').on('click', function () {
        var from = $.trim($('#periodFrom').val());
        var to = $.trim($('#periodTo').val());

        if (! from || ! to) {
            window.alert('ابتدا بازه تاریخی را کامل وارد کنید.');
            return;
        }

        var $button = $(this).prop('disabled', true).text('در حال محاسبه…');

        $.getJSON(sellerTotalUrl, { period_from: from, period_to: to })
            .done(function (response) {
                sellerTotal = parseInt(response.total, 10) || 0;
                $('#sellerTotalDisplay').text(formatRial(sellerTotal));
                recalculate();
            })
            .fail(function () {
                window.alert('محاسبه کل پورسانت ناموفق بود.');
            })
            .always(function () {
                $button.prop('disabled', false).text('محاسبه کل پورسانت');
            });
    });

    $app.on('input change', '.allocation-percentage, .member-share', recalculate);

    $('.split-equally').on('click', function () {
        var $card = $(this).closest('.allocation-card');
        var amount = parseInt($card.find('.allocation-amount').data('amount'), 10) || 0;
        var $shares = $card.find('.member-share');
        var count = $shares.length;
        if (! count) { return; }

        var base = Math.floor(amount / count);
        var remainder = amount - base * count;

        $shares.each(function (index) {
            $(this).val(index === count - 1 ? base + remainder : base);
        });

        recalculate();
    });

    recalculate();
})(jQuery);
</script>
@endpush
