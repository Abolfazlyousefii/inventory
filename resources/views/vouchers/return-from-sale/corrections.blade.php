@extends('layouts.app')
@section('content')
<style>
.src-page{max-width:1200px;margin:auto;color:#183b50}.src-head{border-radius:14px;padding:18px;background:#edf8f6;border:1px solid #b9dfd5;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.src-card{border:1px solid #d5e2ea;border-radius:12px;background:white;margin-top:14px;overflow:hidden}.src-top{padding:14px;background:#f1f6fb;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.src-body{padding:14px}.src-compare{display:grid;grid-template-columns:1fr 1fr;gap:12px}.src-block{padding:12px;border:1px solid #dbe4ec;border-radius:10px}.src-line{border-bottom:1px solid #e3eaf0;padding:8px 0;line-height:1.8}.src-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.src-note{min-height:44px;flex:1;min-width:180px}.src-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px}@media(max-width:650px){.src-compare{grid-template-columns:1fr}.src-actions .btn{flex:1}.src-body,.src-top{padding:12px}}
</style>
<div class="src-page" dir="rtl">
    <header class="src-head"><div><h4 class="mb-1">سوابق اصلاح و درخواست‌های قبلی</h4><div class="small">این صفحه سابقهٔ اصلاحات و درخواست‌های قدیمی را نگه می‌دارد. اصلاحات جدید از خود سند و بر اساس دسترسی مرجوعی یا انبار ثبت می‌شوند.</div></div>
        @if(\App\Support\PageAccessCatalog::userCanRoute(auth()->user(),'vouchers.return-from-sale.index'))<a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.return-from-sale.index') }}">برگشت از فروش</a>@endif
    </header>
    @if($errors->any())<div class="alert alert-danger mt-3">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <form class="src-filter" method="GET"><label for="correctionStatus">وضعیت</label><select class="form-select w-auto" id="correctionStatus" name="status"><option value="all" @selected(($filters['status'] ?? '') === 'all')>همه درخواست‌ها</option>@foreach(\App\Models\SalesReturnCorrection::statusLabels() as $key=>$label)<option value="{{ $key }}" @selected(($filters['status'] ?? 'pending_warehouse') === $key)>{{ $label }}</option>@endforeach</select>@if(isset($filters['document_id']))<input type="hidden" name="document_id" value="{{ $filters['document_id'] }}">@endif<button class="btn btn-primary">نمایش</button></form>
    @forelse($corrections as $correction)
        @php($independent = (int)$correction->requested_by !== (int)auth()->id())
        <article class="src-card"><header class="src-top"><strong>سند {{ $correction->document->document_number }} · درخواست {{ $correction->id }}</strong><span class="badge bg-primary">{{ \App\Models\SalesReturnCorrection::statusLabels()[$correction->status] }}</span></header>
        <div class="src-body"><div class="small mb-2">{{ $correction->requester?->name }} · {{ \App\Support\JalaliDate::dateTime($correction->created_at) }}</div><p><strong>دلیل:</strong> {{ $correction->reason }}</p>
            <div class="src-compare">@foreach(['before_snapshot'=>'قبل از اصلاح','proposed_snapshot'=>'پیشنهاد اصلاح'] as $field=>$label)<section class="src-block"><strong>{{ $label }}</strong>
                @foreach(($correction->$field['items'] ?? []) as $item)
                    @if((int)($item['return_quantity'] ?? 0) > 0)<div class="src-line">{{ $item['product_name_snapshot'] ?? 'کالا' }} · {{ $item['variant_name_snapshot'] ?? '' }}<br>تعداد: <b>{{ number_format((int)$item['return_quantity']) }}</b> · {{ \App\Models\SalesReturnDocumentItem::conditionLabels()[$item['item_condition'] ?? ''] ?? '—' }} · مقصد: {{ $warehouseNames[(int)($item['destination_warehouse_id'] ?? 0)] ?? '—' }}<br>مبلغ: {{ number_format((int)$item['refund_amount']) }} ریال</div>@endif
                @endforeach
                <div class="mt-2 fw-bold">جمع: {{ number_format((int)($correction->$field['total_refund_amount'] ?? 0)) }} ریال</div>
                <div class="small mt-2">مرجع: {{ $correction->$field['document']['reference_number'] ?? '—' }}<br>توضیحات: {{ $correction->$field['document']['description'] ?? '—' }}</div>
            </section>@endforeach</div>
            <div class="mt-3"><b>اختلاف مبلغ:</b> {{ number_format((int)$correction->proposed_snapshot['total_refund_amount'] - (int)$correction->before_snapshot['total_refund_amount']) }} ریال</div>
            @if($correction->warehouse_approved_at)<div class="small mt-2">بررسی انبار: {{ $correction->warehouseApprover?->name }} · {{ \App\Support\JalaliDate::dateTime($correction->warehouse_approved_at) }} — {{ $correction->warehouse_note }}</div>@endif
            @if($correction->finance_approved_at)<div class="small mt-2 text-success">تأیید مالی: {{ $correction->financeApprover?->name }} · {{ \App\Support\JalaliDate::dateTime($correction->finance_approved_at) }} — {{ $correction->finance_note }}</div>@endif
            @if($correction->rejected_at)<div class="small mt-2 text-danger">رد درخواست: {{ $correction->rejecter?->name }} · {{ \App\Support\JalaliDate::dateTime($correction->rejected_at) }} — {{ $correction->rejection_reason }}</div>@endif
            @if(in_array($correction->status,['pending_warehouse','pending_finance']))
                @php($canApprove = $independent && (($correction->status === 'pending_warehouse' && $canWarehouse) || ($correction->status === 'pending_finance' && $canFinance && (int)$correction->warehouse_approved_by !== (int)auth()->id())))
                @if($canApprove || !$independent || ($correction->status === 'pending_warehouse' ? $canWarehouse : $canFinance))
                <form method="POST" class="src-actions mt-3">@csrf<label class="visually-hidden" for="note-{{ $correction->id }}">یادداشت بررسی</label><input id="note-{{ $correction->id }}" class="form-control src-note" name="note" required minlength="3" maxlength="1000" placeholder="یادداشت بررسی یا دلیل رد درخواست">
                    @if($canApprove)<button class="btn btn-success" formaction="{{ route('sales-return-corrections.'.($correction->status === 'pending_warehouse' ? 'warehouse' : 'finance'),$correction) }}">{{ $correction->status === 'pending_warehouse' ? 'تأیید بررسی انبار' : 'تأیید مالی و اعمال اختلاف' }}</button>@endif
                    <button class="btn btn-outline-danger" formaction="{{ route('sales-return-corrections.reject',$correction) }}">{{ !$independent ? 'انصراف از درخواست' : 'رد درخواست' }}</button>
                </form>@endif
            @endif
        </div></article>
    @empty<div class="src-card p-4 text-center">درخواستی با این وضعیت وجود ندارد.</div>@endforelse
    <div class="mt-3">{{ $corrections->links() }}</div>
</div>
@endsection
