@extends('layouts.app')
@section('title', 'گردش حساب '.($customer->display_name ?: 'شخص'))
@push('styles')
<link rel="stylesheet" href="{{ asset('css/account-statement-document.css') }}?v=20261114-shared-book">
@endpush
@section('content')
@php
    $rial = fn ($value) => \App\Support\Currency::formatRial(abs((int) $value));
    $financeActor = auth()->user();
    $canAddPayment = $financeActor && \App\Support\PageAccessCatalog::userCanRoute($financeActor, 'account-statements.payments.store');
    $canManagePayments = $financeActor && !\App\Support\AccountStatementAccess::readOnly($financeActor) && ($financeActor->isAdministrator() || $financeActor->hasAnyRole(['Manager','manager','finance','Accountant']) || $financeActor->can('finance.approve')) && \App\Support\PageAccessCatalog::userCanRoute($financeActor, 'account-statements.payments.edit');
    $balanceText = fn ($value) => $value > 0 ? 'بدهکار' : ($value < 0 ? 'بستانکار' : 'تسویه');
    $canManageDocuments = $canManagePayments || $canAdjustBalance;
@endphp
<div class="as-document">
    <header class="asd-toolbar">
        <div><span class="asd-eyebrow">دفتر حساب شخص</span><h1>{{ $customer->display_name ?: 'شخص' }}</h1><div class="asd-identity"><span>کد: {{ $customer->crm_customer_id ?: $customer->id }}</span><span dir="ltr">{{ $customer->mobile ?: '—' }}</span></div></div>
        <div class="asd-actions">
            @if($canAddPayment)<button type="button" class="btn asd-primary" id="openPaymentModalBtn" data-bs-toggle="modal" data-bs-target="#paymentModal">+ افزودن پرداخت</button>@endif
            @if($canAdjustBalance)<button type="button" class="btn asd-secondary" data-bs-toggle="modal" data-bs-target="#financialDocumentModal">+ افزودن سند مالی</button>@endif
            @if($canAdjustBalance)<button type="button" class="btn asd-secondary" data-bs-toggle="modal" data-bs-target="#personnelDocumentModal">+ افزودن سند پرسنلی</button>@endif
            @if($financeActor && \App\Support\AccountStatementAccess::readOnly($financeActor))<span class="badge bg-light text-secondary">فقط مشاهده</span>@endif
            @if($canAdjustBalance)<button type="button" class="btn asd-secondary" data-bs-toggle="modal" data-bs-target="#balanceAdjustmentModal">اصلاح مانده</button>@endif
            <a href="{{ route('account-statements.index') }}" class="btn asd-secondary">بازگشت</a>
        </div>
    </header>
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @include('account-statements.partials.summary', ['openingBalance' => $statementTotals['opening_balance']])
    <section class="asd-book">
        <div class="asd-book-heading"><h2>گردش حساب</h2><span>بر اساس تاریخ سند، جدیدترین ابتدا · مبالغ به ریال</span></div>
        @include('account-statements.partials.ledger-table')
        <footer class="asd-book-footer"><span>{{ number_format($ledgers->total()) }} ثبت</span>{{ $ledgers->links() }}</footer>
    </section>
    @include('account-statements.partials.balance-adjustment', ['collapseBalanceHistory' => true])
</div>
@if($canAddPayment)@include('payments.entry-modal', ['paymentModalId' => 'paymentModal', 'paymentAction' => route('account-statements.payments.store', $customer->id), 'paymentInvoiceOptions' => $paymentInvoiceOptions])@endif
@if($canAdjustBalance)@include('account-statements.partials.personnel-document')@endif
@if($canAdjustBalance)@include('account-statements.partials.financial-document-modal', ['financialDocumentAction'=>route('account-statements.financial-documents.store',$customer), 'financialDocumentRoles'=>['customer'=>'دفتر مشتری'], 'financialDocumentPersonName'=>$customer->display_name])@endif
@endsection

@push('scripts')<script src="{{ asset('js/account-statement-display.js') }}?v=20261011" defer></script>@endpush
