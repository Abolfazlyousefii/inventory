@php
    $selectedCustomer = $selectedCustomer ?? null;
    $selectedPayload = $selectedCustomer ? [
        'id' => $selectedCustomer->id,
        'name' => $selectedCustomer->display_name,
        'mobile' => $selectedCustomer->mobile,
        'customer_code' => $selectedCustomer->crm_customer_id ?: (string) $selectedCustomer->id,
    ] : null;
@endphp
<div class="sr-filter-customer" data-customer-picker data-search-url="{{ route('vouchers.return-from-sale.customers.search') }}" data-selected='@json($selectedPayload)'>
    <input type="hidden" name="customer_id" data-customer-id value="{{ $filters['customer_id'] ?? '' }}">
    <div class="position-relative">
        <input type="text" class="form-control form-control-sm" data-customer-search placeholder="نام، موبایل، کد یا شناسه مشتری" autocomplete="off" value="{{ $selectedCustomer?->display_name }}">
        <div class="sr-filter-results d-none" data-customer-results role="listbox"></div>
    </div>
    <div class="small mt-1 d-flex justify-content-between gap-2" data-customer-selected>
        <span class="text-muted">{{ $selectedCustomer ? (($selectedCustomer->display_name ?: '—').' | '.($selectedCustomer->mobile ?: '—').' | کد: '.($selectedCustomer->crm_customer_id ?: $selectedCustomer->id)) : 'مشتری انتخاب نشده است.' }}</span>
        <button type="button" class="btn btn-link btn-sm p-0 {{ $selectedCustomer ? '' : 'd-none' }}" data-customer-clear>پاک‌کردن</button>
    </div>
</div>
