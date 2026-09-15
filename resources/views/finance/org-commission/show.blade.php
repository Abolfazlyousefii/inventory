@extends('layouts.app')
@section('title', 'سند پورسانت اداری '.$document->document_number)
@section('page-title', 'مالی / پورسانت اداری')

@section('content')
@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $statusLabels = ['draft' => 'پیش‌نویس', 'confirmed' => 'تأیید‌شده', 'finalized' => 'نهایی‌شده'];
    $statusClasses = ['draft' => 'bg-warning text-dark', 'confirmed' => 'bg-info text-dark', 'finalized' => 'bg-success'];
    $remaining = (int) $document->total_seller_commission - (int) $document->total_allocated;
@endphp

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                سند پورسانت اداری {{ $document->document_number }}
                <span class="badge {{ $statusClasses[$document->status] ?? 'bg-secondary' }} align-middle">{{ $statusLabels[$document->status] ?? $document->status }}</span>
            </h1>
            <p class="text-muted mb-0">
                ایجاد: {{ JalaliDate::date($document->created_at) }} توسط {{ $document->creator?->name ?? '—' }}
                @if($document->confirmer) | تأیید: {{ JalaliDate::dateTime($document->confirmed_at) }} توسط {{ $document->confirmer->name }} @endif
                @if($document->finalizer) | نهایی: {{ JalaliDate::dateTime($document->finalized_at) }} توسط {{ $document->finalizer->name }} @endif
            </p>
        </div>
        <a href="{{ route('finance.org-commission.index', ['tab' => 'documents']) }}" class="btn btn-outline-secondary">بازگشت به فهرست</a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <span class="d-block text-muted small">بازه سند</span>
                <strong>{{ JalaliDate::date($document->period_from) }} — {{ JalaliDate::date($document->period_to) }}</strong>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <span class="d-block text-muted small">کل پورسانت فروشندگان</span>
                <strong>{{ Currency::formatRial($document->total_seller_commission) }}</strong>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <span class="d-block text-muted small">مبلغ کل تخصیص</span>
                <strong>{{ Currency::formatRial($document->total_allocated) }}</strong>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <span class="d-block text-muted small">باقی‌مانده تخصیص‌نشده</span>
                <strong class="{{ $remaining < 0 ? 'text-danger' : '' }}">{{ Currency::formatRial($remaining) }}</strong>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">تخصیص واحدها</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>واحد</th><th>درصد</th><th>مبلغ تخصیص</th><th>تعداد اعضا</th><th class="text-end">جزئیات</th></tr>
                </thead>
                <tbody>
                    @forelse($document->allocations as $allocation)
                        <tr>
                            <td class="fw-bold">{{ $allocation->department?->name ?? '—' }}</td>
                            <td>{{ rtrim(rtrim($allocation->percentage, '0'), '.') }}٪</td>
                            <td>{{ Currency::formatRial($allocation->allocated_amount) }}</td>
                            <td>{{ $allocation->memberShares->count() }}</td>
                            <td class="text-end">
                                @if($allocation->memberShares->isNotEmpty())
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#allocationMembers{{ $allocation->id }}">اعضا</button>
                                @else
                                    <span class="text-muted small">بدون تقسیم</span>
                                @endif
                            </td>
                        </tr>
                        @if($allocation->memberShares->isNotEmpty())
                            <tr class="p-0">
                                <td colspan="5" class="p-0 border-0">
                                    <div class="collapse" id="allocationMembers{{ $allocation->id }}">
                                        <table class="table table-sm mb-0 bg-light">
                                            <thead><tr><th>عضو</th><th>سمت</th><th>مبلغ سهم</th><th>یادداشت</th></tr></thead>
                                            <tbody>
                                                @foreach($allocation->memberShares as $share)
                                                    <tr>
                                                        <td>{{ $share->member?->name ?? '—' }}</td>
                                                        <td class="text-muted small">{{ $share->member?->role ?: '—' }}</td>
                                                        <td>{{ Currency::formatRial($share->share_amount) }}</td>
                                                        <td class="text-muted small">{{ $share->notes ?: '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">این سند تخصیصی ندارد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($document->notes)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">یادداشت سند</div>
            <div class="card-body"><p class="mb-0">{{ $document->notes }}</p></div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2">
        @if($document->isDraft())
            <a href="{{ route('finance.org-commission.edit', $document) }}" class="btn btn-outline-secondary">ویرایش</a>
            <form method="POST" action="{{ route('finance.org-commission.confirm', $document) }}">
                @csrf
                <button type="submit" class="btn btn-primary">تأیید سند</button>
            </form>
            <form method="POST" action="{{ route('finance.org-commission.destroy', $document) }}" id="orgDocumentDeleteForm">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">حذف سند</button>
            </form>
        @elseif($document->isConfirmed())
            <form method="POST" action="{{ route('finance.org-commission.finalize', $document) }}">
                @csrf
                <button type="submit" class="btn btn-success">نهایی‌سازی سند</button>
            </form>
        @endif
        <a href="{{ route('finance.org-commission.print', $document) }}" class="btn btn-outline-dark" target="_blank">چاپ</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    $('#orgDocumentDeleteForm').on('submit', function (event) {
        if (! window.confirm('این سند پیش‌نویس حذف شود؟')) {
            event.preventDefault();
        }
    });
})(jQuery);
</script>
@endpush
