@extends('layouts.app')

@section('title', 'فاکتورها و پیش‌فاکتورهای من')
@section('content_class', 'app-content-wide')
@section('meta')
  <link rel="stylesheet" href="{{ asset('css/pages/my-preinvoice.css') }}">
  <script src="{{ asset('js/pages/my-preinvoice.js') }}" defer></script>
@endsection

@section('content')
@php
  use Illuminate\Support\Str;
  use App\Services\MySalesDocumentsService;

  $toJalali = function ($date) {
      if (!$date) return '—';
      if (class_exists(\Morilog\Jalali\Jalalian::class)) return \Morilog\Jalali\Jalalian::fromDateTime($date)->format('Y/m/d H:i');
      return optional($date)->format('Y/m/d H:i') ?? '—';
  };
  $statusBadge = fn($summary) => match($summary['status_key']) {
      \App\Models\PreinvoiceOrder::STATUS_DRAFT => 'text-bg-secondary',
      \App\Models\PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
      \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
      \App\Models\Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'text-bg-danger',
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
      \App\Models\Invoice::STATUS_NOT_SHIPPED => 'text-bg-dark',
      \App\Models\Invoice::STATUS_READY_TO_SHIP,
      \App\Models\Invoice::STATUS_SHIPPED => 'text-bg-success',
      default => 'text-bg-info',
  };
  $emptyText = match($activeTab) {
      MySalesDocumentsService::TAB_DRAFTS => 'پیش‌نویسی ذخیره نشده است.',
      MySalesDocumentsService::TAB_SHIPPED => 'هنوز فاکتور ارسال‌شده‌ای ندارید.',
      MySalesDocumentsService::TAB_NEEDS_CORRECTION => 'سندی برای بررسی و اصلاح به شما ارجاع نشده است.',
      default => 'در حال حاضر پیش‌فاکتور یا فاکتور فعالی در جریان ندارید.',
  };
  $hasMoreFilters = filled($filters['date_from'] ?? '') || filled($filters['date_to'] ?? '') || ! empty($filters['changed_only'] ?? false) || filled($filters['customer'] ?? '');
@endphp

<div class="my-sales-page">
  <header class="my-sales-page__header">
    <div class="my-sales-page__heading">
      <h1>فاکتورها و پیش‌فاکتورهای من</h1>
      <p>مدیریت پیش‌نویس‌ها، اسناد جاری، فاکتورهای ارسال‌شده و موارد نیازمند اصلاح</p>
    </div>
    <a href="{{ route('preinvoice.create') }}" class="my-sales-page__create btn btn-primary">+ ثبت پیش‌فاکتور جدید</a>
  </header>

  <nav class="my-sales-tabs" role="tablist" aria-label="دسته‌بندی اسناد من">
    @foreach($tabs as $tabKey => $tab)
      @php
        $isNeeds = $tabKey === MySalesDocumentsService::TAB_NEEDS_CORRECTION;
        $tabCount = $tabCounts[$tabKey] ?? 0;
      @endphp
      <a class="my-sales-tabs__item {{ $activeTab === $tabKey ? 'is-active' : '' }} {{ $isNeeds && $tabCount > 0 ? 'needs-attention' : '' }}" href="{{ route('preinvoice.my.index', array_merge(request()->except('page', 'status'), ['tab' => $tabKey])) }}" role="tab" aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}">
        <span class="my-sales-tabs__label">@if($isNeeds && $tabCount > 0)<span class="my-sales-tabs__dot" aria-hidden="true"></span>@endif{{ $tab['label'] }}</span>
        <span class="my-sales-tabs__count">{{ number_format($tabCount) }}</span>
      </a>
    @endforeach
  </nav>

  <section class="my-sales-filters" aria-label="فیلتر اسناد">
    <form class="my-sales-filter" method="GET" action="{{ route('preinvoice.my.index') }}">
      <input type="hidden" name="tab" value="{{ $activeTab }}">
      <div class="my-sales-filter__main">
        <label class="my-sales-filter__field my-sales-filter__field--search"><span>جستجوی عمومی</span><input name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="شماره سند"></label>
        <label class="my-sales-filter__field"><span>وضعیت</span><select name="status" class="form-select"><option value="">همه وضعیت‌های این تب</option>@foreach($statusLabels as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></label>
        <label class="my-sales-filter__field"><span>نوع سند</span><select name="type" class="form-select"><option value="">همه</option><option value="preinvoice" @selected(($filters['type'] ?? '') === 'preinvoice')>پیش‌فاکتور</option><option value="invoice" @selected(($filters['type'] ?? '') === 'invoice')>فاکتور</option></select></label>
        <div class="my-sales-filter__actions"><button class="btn btn-primary" type="submit">اعمال</button><a href="{{ route('preinvoice.my.index', ['tab' => $activeTab]) }}" class="btn btn-outline-secondary">پاک‌کردن</a></div>
        <button class="btn btn-outline-primary my-sales-filter__more" type="button" data-bs-toggle="collapse" data-bs-target="#mySalesMoreFilters" aria-expanded="{{ $hasMoreFilters ? 'true' : 'false' }}" aria-controls="mySalesMoreFilters">فیلترهای بیشتر</button>
      </div>
      <div class="collapse {{ $hasMoreFilters ? 'show' : '' }}" id="mySalesMoreFilters">
        <div class="my-sales-filter__more-panel">
          <label class="my-sales-filter__field"><span>نام مشتری</span><input name="customer" class="form-control" value="{{ $filters['customer'] ?? '' }}" placeholder="نام مشتری"></label>
          <label class="my-sales-filter__field"><span>از تاریخ</span><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}"></label>
          <label class="my-sales-filter__field"><span>تا تاریخ</span><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}"></label>
          <label class="my-sales-filter__check"><input class="form-check-input" type="checkbox" name="changed_only" value="1" @checked($filters['changed_only'] ?? false)> فقط تغییرکرده‌ها</label>
        </div>
      </div>
    </form>
  </section>

  <section class="my-sales-documents" data-sales-documents>
    <div class="my-sales-documents__header" aria-hidden="true"><span>شماره و نوع</span><span>مشتری</span><span>وضعیت</span><span>مبلغ</span><span>اقلام</span><span>آخرین تغییر</span><span>عملیات</span></div>
    @forelse($orders as $order)
      @php
        $summary = $order->current_document;
        $isNeedsAction = $summary['bucket'] === MySalesDocumentsService::BUCKET_NEEDS_CORRECTION;
        $isDraft = $summary['bucket'] === MySalesDocumentsService::BUCKET_DRAFT;
        $documentDomId = 'sales-document-details-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $summary['document_number']);
      @endphp
      <article class="my-sales-document {{ $isNeedsAction ? 'needs-action' : '' }}" data-document-card>
        <div class="my-sales-document__summary">
          <div class="my-sales-document__identity"><strong class="my-sales-document__code">{{ Str::limit($summary['document_number'], 18, '…') }}</strong><span class="my-sales-document__type">{{ $summary['has_invoice'] ? 'فاکتور' : 'پیش‌فاکتور' }}</span>@if($summary['has_invoice'])<small class="my-sales-document__preinvoice">پیش‌فاکتور: {{ $summary['preinvoice_uuid'] }}</small>@endif</div>
          <div class="my-sales-document__customer"><span class="my-sales-document__label">مشتری</span><strong>{{ $summary['customer_name'] ?: '—' }}</strong></div>
          <div class="my-sales-document__status"><span class="my-sales-document__label">وضعیت</span><span class="badge {{ $statusBadge($summary) }}">{{ $summary['status_label'] }}</span>@if($isNeedsAction)<small class="my-sales-document__notice">{{ $summary['needs_action_label'] }}</small>@endif @if(!$summary['has_invoice'] && $order->stock_frozen_until) @include('preinvoice.partials.reservation-timer', ['order' => $order, 'compact' => true]) @endif</div>
          <div class="my-sales-document__amount"><span class="my-sales-document__label">مبلغ</span><strong>{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</strong></div>
          <div class="my-sales-document__items"><span class="my-sales-document__label">اقلام</span><strong>{{ number_format($summary['items_count']) }} قلم</strong></div>
          <div class="my-sales-document__date"><span class="my-sales-document__label">آخرین تغییر</span><strong>{{ $toJalali($summary['last_changed_at']) }}</strong></div>
          <div class="my-sales-document__actions {{ $summary['can_edit'] ? 'my-sales-document__actions--editable' : '' }}">
            <a href="{{ $summary['primary_action_url'] }}" class="btn btn-sm {{ $summary['can_edit'] ? 'btn-primary' : 'btn-outline-primary' }}" data-document-action>{{ $summary['primary_action_label'] }}</a>
            @if($summary['secondary_action_url'])<a href="{{ $summary['secondary_action_url'] }}" class="btn btn-sm btn-outline-primary" data-document-action>{{ $summary['secondary_action_label'] }}</a>@endif
            <button type="button" class="btn btn-sm btn-outline-secondary" data-document-toggle aria-expanded="false" aria-controls="{{ $documentDomId }}"><span class="document-toggle-text">جزئیات</span></button>
          </div>
        </div>
        @if($isNeedsAction || $isDraft || in_array($summary['status_key'], [\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, \App\Models\Invoice::STATUS_NOT_SHIPPED], true))
          <div class="my-sales-document__badges">@if($isNeedsAction)<span class="badge text-bg-danger">{{ $summary['needs_action_label'] }}</span><span class="badge text-bg-warning text-dark">{{ $summary['needs_action_reason'] }}</span>@endif @if($isDraft)<span class="badge text-bg-secondary">پیش‌نویس</span><span class="badge {{ $order->is_auto_draft ? 'text-bg-info' : 'text-bg-light border text-dark' }}">{{ $order->is_auto_draft ? 'ذخیره خودکار' : 'در انتظار تکمیل' }}</span>@endif @if(in_array($summary['status_key'], [\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, \App\Models\Invoice::STATUS_NOT_SHIPPED], true))<span class="badge text-bg-dark">لغوشده</span>@endif</div>
        @endif
        @if($isNeedsAction)<div class="my-sales-document__message">{{ $summary['needs_action_message'] }}</div>@endif
        <div class="my-sales-document__details" id="{{ $documentDomId }}" data-document-details hidden>
          <div class="my-sales-document__details-grid">
            <div class="my-sales-document__meta"><span>موبایل مشتری</span><strong>{{ $summary['customer_mobile'] ?: '—' }}</strong></div><div class="my-sales-document__meta"><span>مبلغ</span><strong>{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</strong></div><div class="my-sales-document__meta"><span>تعداد اقلام</span><strong>{{ number_format($summary['items_count']) }} قلم</strong></div><div class="my-sales-document__meta"><span>پرداخت‌شده</span><strong>{{ is_null($summary['paid_amount']) ? '—' : \App\Support\Currency::formatRial($summary['paid_amount']) }}</strong></div><div class="my-sales-document__meta"><span>مانده</span><strong>{{ is_null($summary['remaining_amount']) ? '—' : \App\Support\Currency::formatRial($summary['remaining_amount']) }}</strong></div><div class="my-sales-document__meta"><span>شماره پیش‌فاکتور اولیه</span><strong class="my-sales-document__code">{{ $summary['preinvoice_uuid'] ?: '—' }}</strong></div><div class="my-sales-document__meta"><span>اقدام بعدی</span><strong>{{ $summary['next_action_label'] }}</strong></div><div class="my-sales-document__meta"><span>آخرین ذخیره یا ارجاع</span><strong>{{ $isNeedsAction ? $toJalali($summary['return_at']) : ($order->is_auto_draft ? $toJalali($order->auto_saved_at) : $toJalali($summary['last_changed_at'])) }}</strong></div>
          </div>
          @if($isNeedsAction)<div class="my-sales-document__details-grid my-sales-document__details-grid--referral"><div class="my-sales-document__meta"><span>ارجاع‌دهنده</span><strong>{{ $summary['return_by'] ?: '—' }}</strong></div><div class="my-sales-document__meta"><span>واحد ارجاع‌دهنده</span><strong>{{ $summary['return_unit'] ?: '—' }}</strong></div><div class="my-sales-document__meta"><span>علت ارجاع</span><strong>{{ $summary['return_reason'] ?: 'علت ارجاع ثبت نشده است.' }}</strong></div><div class="my-sales-document__meta"><span>توضیحات ارجاع</span><strong>{{ $summary['return_note'] ?: '—' }}</strong></div></div>@endif
          <div class="my-sales-document__detail-actions"><a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary">{{ $summary['has_invoice'] ? 'مشاهده فاکتور' : 'مشاهده سند' }}</a>@if($activeTab === MySalesDocumentsService::TAB_ACTIVE || $activeTab === MySalesDocumentsService::TAB_SHIPPED)<a href="{{ $summary['print_url'] }}" target="_blank" class="btn btn-sm btn-outline-dark">چاپ</a>@endif @if($summary['edit_url'] && ($isNeedsAction || $isDraft))<a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-warning">{{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED ? 'بررسی موجودی و اصلاح' : ($isDraft ? 'ادامه ویرایش' : 'اصلاح سند') }}</a>@endif <button type="button" class="btn btn-sm btn-outline-secondary" data-document-toggle aria-expanded="false" aria-controls="{{ $documentDomId }}">بستن جزئیات</button></div>
        </div>
      </article>
    @empty
      <div class="my-sales-empty"><p>{{ request()->except('tab', 'page') ? 'سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.' : $emptyText }}</p>@if($activeTab === MySalesDocumentsService::TAB_ACTIVE)<a href="{{ route('preinvoice.create') }}" class="btn btn-sm btn-primary">ثبت پیش‌فاکتور جدید</a>@endif</div>
    @endforelse
  </section>

  @if(method_exists($orders, 'links'))<footer class="my-sales-pagination">{{ $orders->appends(request()->except('page'))->links() }}</footer>@endif
</div>
@endsection
