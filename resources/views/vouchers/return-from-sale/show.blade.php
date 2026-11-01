@extends('layouts.app')
@php use App\Models\SalesReturnDocument; use App\Models\SalesReturnDocumentItem; use Morilog\Jalali\Jalalian; $labels=SalesReturnDocument::statusLabels(); $sources=SalesReturnDocument::sourceTypeLabels(); @endphp
@section('content')
	<div class="container-fluid" dir="rtl">
        @php
            $protectedReturn=$document->isApplied() && app(\App\Services\SalesReturnCorrectionService::class)->requiresWarehouse($document);
            $canDirectReturn=$document->isApplied() && auth()->user() && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(),$protectedReturn?'warehouse.inbound.receive':'vouchers.return-from-sale.applied.update');
        @endphp
        @if($document->isApplied())
        <div class="alert alert-info">
            <div>{{ $protectedReturn ? 'دریافت انباری این سند ثبت شده است؛ تغییر تعداد و ابطال فقط توسط مسئول مجاز انبار انجام می‌شود.' : 'این سند مرجوعی قابل ویرایش و ابطال با حفظ سابقه است؛ موجودی مرکزی بدون تأیید دریافت افزایش نمی‌یابد.' }}</div>
            @if($pendingCorrection ?? null)
                <strong>درخواست قبلی {{ $pendingCorrection->id }}: {{ \App\Models\SalesReturnCorrection::statusLabels()[$pendingCorrection->status] }} — ابتدا این درخواست تعیین تکلیف شود.</strong>
            @elseif($canDirectReturn)
                <a class="btn btn-sm btn-primary mt-2" href="{{ route('vouchers.return-from-sale.applied.edit',$document) }}">{{ $protectedReturn ? 'اصلاح دریافت' : 'ویرایش مرجوعی' }}</a>
                <details class="mt-3"><summary>ابطال سند با حفظ سابقه</summary><form method="POST" action="{{ route('vouchers.return-from-sale.applied.void',$document) }}" class="mt-2">@csrf
                    <input type="hidden" name="document_version" value="{{ \App\Support\InboundReviewVersion::token($document) }}">
                    <label class="form-label">دلیل ابطال</label><textarea name="reason" class="form-control" required minlength="3" maxlength="1000"></textarea>
                    <p class="small mt-2">فقط اثر ثبت‌شدهٔ همین سند برمی‌گردد. کمبود موجودی آزاد، کل عملیات را متوقف می‌کند.</p><button class="btn btn-sm btn-danger">تأیید ابطال</button>
                </form></details>
            @endif
            <a class="d-inline-block mt-2" href="{{ route('sales-return-corrections.index',['document_id'=>$document->id,'status'=>'all']) }}">سوابق اصلاح و درخواست‌های قبلی</a>
        </div>
        @endif
        @if($document->isPendingWarehouse())
        <details class="card p-3 mb-3"><summary>لغو قبل از دریافت انبار</summary><form method="POST" action="{{ route('vouchers.return-from-sale.cancel',$document) }}" class="mt-2">@csrf
            <input type="hidden" name="document_version" value="{{ \App\Support\InboundReviewVersion::token($document) }}">
            <label class="form-label">دلیل لغو</label><textarea name="cancel_reason" class="form-control" required minlength="3" maxlength="1000"></textarea><button class="btn btn-sm btn-warning mt-2">لغو سند و رسید منتظر دریافت</button>
        </form></details>
        @endif

		<div class="d-flex justify-content-between mb-3 flex-wrap gap-2"><div><h5>سند برگشت از فروش {{ $document->document_number }}</h5><div class="text-muted small">{{ $sources[$document->source_type] ?? '—' }} | {{ $document->created_at ? Jalalian::fromDateTime($document->created_at)->format('Y/m/d H:i') : '—' }}</div></div><div class="d-flex gap-2 flex-wrap">@if($document->isPendingWarehouse())<a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.return-from-sale.edit',$document) }}">اصلاح سند در انتظار انبار</a>@endif
@if($document->isDraft())<a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.return-from-sale.edit',$document) }}">ویرایش</a><form method="POST" action="{{ route('vouchers.return-from-sale.apply',$document) }}">@csrf<button class="btn btn-sm btn-success">ارسال به صف انبار</button></form><form method="POST" action="{{ route('vouchers.return-from-sale.cancel',$document) }}">@csrf<button class="btn btn-sm btn-warning">لغو Draft</button></form>@endif
<a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.return-from-sale.print',$document) }}">چاپ</a><a class="btn btn-sm btn-light" href="{{ route(auth()->user() && !\App\Support\PageAccessCatalog::userCanRoute(auth()->user(),'vouchers.return-from-sale.index') ? 'warehouse.inbound.index' : 'vouchers.return-from-sale.index') }}">بازگشت</a></div></div>
		@if($document->isPendingWarehouse())<div class="alert alert-warning border mb-3"><strong>در انتظار دریافت انبار</strong><div class="small mt-1">این سند هنوز روی موجودی و حساب مشتری اثر نهایی نگذاشته است. پس از بررسی مدیر انبار، فقط تعداد تأییدشده وارد موجودی و سند نهایی می‌شود.</div></div>@endif
		<div class="row g-3 mb-3"><div class="col-md-4"><div class="card"><div class="card-body"><h6>اطلاعات سند</h6><div>وضعیت: <b>{{ $labels[$document->status] ?? '—' }}</b></div><div>مبلغ کل: {{ number_format($document->total_refund_amount) }}</div><div>تعداد کل: {{ number_format($document->total_quantity) }}</div><div>علت: {{ $document->return_reason ?: '—' }}</div></div></div></div><div class="col-md-4"><div class="card"><div class="card-body"><h6>مشتری</h6><div>{{ $document->customer?->display_name ?: '—' }}</div><div>{{ $document->customer?->mobile ?: '—' }}</div><div>کد: {{ $document->customer?->crm_customer_id ?: $document->customer_id }}</div><div>مانده فعلی: {{ number_format((int)($document->customer?->balance ?? 0)) }}</div></div></div></div><div class="col-md-4"><div class="card"><div class="card-body"><h6>مرجع</h6>@if($document->isInternal())<div>فاکتور داخلی: @if($document->invoice && $canViewSourceInvoice)<a href="{{ route('vouchers.sales.show',$document->invoice->uuid) }}">{{ $document->invoice->uuid }}</a>@elseif($document->invoice){{ $document->invoice->uuid }}@else — @endif</div>@else<div>فاکتور سازه‌حساب: {{ $document->external_invoice_number ?: '—' }}</div><div>تاریخ: {{ $document->external_invoice_date ? Jalalian::fromDateTime($document->external_invoice_date)->format('Y/m/d') : '—' }}</div>@endif<div>مرجع: {{ $document->reference_number ?: '—' }}</div></div></div></div></div>
		<div class="card mb-3"><div class="card-header bg-white fw-bold">اقلام</div><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>کالا</th><th>تنوع</th><th>کد</th><th>تعداد</th><th>وضعیت</th><th>مقصد</th><th>مبلغ واحد</th><th>مبلغ کل</th></tr></thead><tbody>@foreach($document->items as $item)<tr><td>{{ $item->product_name_snapshot ?: $item->product?->name }}</td><td>{{ $item->variant_name_snapshot ?: $item->variant?->variant_name }}</td><td>{{ $item->sku_snapshot ?: $item->barcode_snapshot ?: '—' }}</td><td>{{ number_format($item->return_quantity) }}</td><td>{{ SalesReturnDocumentItem::conditionLabels()[$item->item_condition]??'—' }}</td><td>{{ $item->destinationWarehouse?->name }}</td><td>{{ number_format($item->refund_unit_price) }}</td><td>{{ number_format($item->refund_amount) }}</td></tr>@endforeach</tbody></table></div></div>
		<div class="row g-3"><div class="col-md-6"><div class="card"><div class="card-body"><h6>Audit</h6><div>ایجادکننده: {{ $document->creator?->name ?: '—' }} | {{ $document->created_at ? Jalalian::fromDateTime($document->created_at)->format('Y/m/d H:i') : '—' }}</div><div>نهایی‌کننده: {{ $document->applier?->name ?: '—' }} | {{ $document->applied_at ? Jalalian::fromDateTime($document->applied_at)->format('Y/m/d H:i') : '—' }}</div>@if($document->isApplied())<div>Ledger Credit: {{ number_format($healthCheck['ledger_amount'] ?? 0) }}</div><div>Stock Movement count: {{ number_format($healthCheck['stock_movement_count'] ?? 0) }}</div>@endif</div></div></div><div class="col-md-6"><div class="card"><div class="card-body"><h6>وضعیت ثبت مالی و انباری</h6>@if($document->isPendingWarehouse())<div class="fw-bold text-warning">در انتظار تأیید مدیر انبار</div><div class="small text-muted">تا زمان تأیید صف، موجودی و بستانکاری مشتری ثبت نهایی نمی‌شود.</div>@elseif($document->isApplied())<div class="fw-bold {{ ($healthCheck['ok']??false)?'text-success':'text-danger' }}">{{ ($healthCheck['ok']??false)?'سالم':'نیاز به بررسی' }}</div>@foreach(($healthCheck['checks']??[]) as $name=>$ok)<div class="small">{{ $ok ? '✓' : '×' }} {{ $name }}</div>@endforeach<div class="text-muted small">این Health Check فقط خواندنی است و هیچ Sync یا Repair انجام نمی‌دهد.</div>@else<div class="text-muted">این سند هنوز ثبت نهایی نشده است.</div>@endif</div></div></div></div>
	</div>
    @php($pendingRevisions = $document->revisions()->orderBy('id')->with('creator:id,name')->get())
    @if($pendingRevisions->isNotEmpty())
    <details class="card p-3 mt-3"><summary>سوابق تغییرات سند ({{ $pendingRevisions->count() }})</summary>
        @foreach($pendingRevisions as $revision)
        <article class="border-top pt-3 mt-3"><strong>{{ $revision->creator?->name ?: 'کاربر' }}</strong> · {{ \App\Support\JalaliDate::dateTime($revision->created_at) }}<p class="mt-2">{{ $revision->reason }}</p>
        <div class="row g-2">@foreach(['before_snapshot'=>'قبل از اصلاح','after_snapshot'=>'بعد از اصلاح'] as $field=>$label)<div class="col-md-6"><b>{{ $label }}</b>@foreach(($revision->$field['items'] ?? []) as $line)<div class="small mt-1">{{ $line['product_name_snapshot'] ?? 'کالا' }} · {{ $line['variant_name_snapshot'] ?? '' }} — تعداد: {{ $line['return_quantity'] ?? 0 }} · {{ \App\Models\SalesReturnDocumentItem::conditionLabels()[$line['item_condition'] ?? ''] ?? '—' }} · مبلغ: {{ number_format((int)($line['refund_amount'] ?? 0)) }} ریال</div>@endforeach</div>@endforeach</div>
        </article>@endforeach
    </details>@endif

@endsection
