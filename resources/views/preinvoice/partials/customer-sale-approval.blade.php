<input type="hidden" name="sale_authorization_context" id="sale_authorization_context" value="{{ old('sale_authorization_context', (string) \Illuminate\Support\Str::uuid()) }}">
<div class="sale-approval-widget" id="customerSaleApproval" hidden data-request="{{ route('commercial.sale-approvals.request') }}" data-verify-base="{{ url('/commercial/sale-approvals') }}" data-customer-base="{{ url('/preinvoice/api/customers') }}">
    <div class="sale-approval-heading"><strong data-sale-owner></strong><button type="button" class="btn btn-sm btn-outline-primary" data-sale-request>درخواست کد تأیید</button></div>
    <div class="sale-approval-line" data-sale-code-panel hidden><label for="saleApprovalCode">کد تأیید همکار</label><input id="saleApprovalCode" class="form-control" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="کد ۶ رقمی"><button type="button" class="btn btn-sm btn-success" data-sale-verify>تأیید و انتخاب</button></div>
    <p class="sale-approval-status mb-0" data-sale-status role="status" aria-live="polite"></p>
</div>
@push('styles')<link rel="stylesheet" href="{{ asset('css/customer-sale-approval.css') }}?v={{ filemtime(public_path('css/customer-sale-approval.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/customer-sale-approval.js') }}?v={{ filemtime(public_path('js/customer-sale-approval.js')) }}"></script>@endpush
