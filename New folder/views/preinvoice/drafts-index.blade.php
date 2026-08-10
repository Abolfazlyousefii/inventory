@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
  use Illuminate\Support\Str;

  $rial = fn ($value) => number_format((int) $value) . ' ریال';
  $fmtDate = fn ($date) => $date ? Jalalian::fromDateTime($date)->format('Y/m/d H:i') : '—';
  $dateFa = $fmtDate;

  $preinvoiceReturnReasons = ['اصلاح قیمت','اصلاح تعداد','اطلاعات مشتری ناقص','مشکل شرایط پرداخت','مغایرت کالا','نیاز به توضیح بیشتر','سایر'];
  $preinvoiceCancelReasons = ['درخواست مشتری','عدم تأیید شرایط پرداخت','قیمت یا شرایط نامعتبر','ثبت اشتباه','انصراف فروشنده','سایر'];
  $invoiceReturnReasons = ['مغایرت تعداد','مغایرت قیمت','مغایرت تخفیف','کالای اشتباه','توضیحات ناکافی','سایر'];
@endphp

@section('content')
<style>
  .finance-page{background:#f8fafc}.fq-title{font-weight:700;font-size:1.05rem}.fq-muted{color:#64748b}.fq-stat{border:1px solid #e2e8f0;border-radius:.85rem;background:#fff;padding:.8rem}.fq-stat .value{font-size:1.25rem;font-weight:700}.fq-tabs .nav-link{border:1px solid #e2e8f0;color:#334155;background:#fff}.fq-tabs .nav-link.active{background:#0f172a;color:#fff}.fq-card{border:1px solid #e2e8f0;border-radius:.9rem;background:#fff;box-shadow:none}.fq-table{table-layout:fixed}.fq-table th{font-size:.75rem;color:#475569;white-space:nowrap}.fq-table td{font-size:.82rem;vertical-align:middle}.fq-actions{display:flex;gap:.35rem;justify-content:flex-end;flex-wrap:wrap}.reservation-box{min-width:150px}.reservation-countdown{direction:ltr;unicode-bidi:plaintext;font-variant-numeric:tabular-nums;font-weight:700}.timer-green{color:#15803d}.timer-yellow{color:#a16207}.timer-red{color:#b91c1c;animation:pulse 1s infinite}.timer-expired{color:#dc2626}.timer-progress{height:4px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:.35rem}.timer-progress>span{display:block;height:100%;background:#22c55e}.mobile-list{display:none}.doc-mobile{border:1px solid #e2e8f0;border-radius:.85rem;background:#fff;padding:.85rem}.doc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}.doc-grid .label{display:block;color:#64748b;font-size:.72rem}.empty-state{padding:2.2rem;text-align:center;color:#64748b}.modal-content{border-radius:1rem}@keyframes pulse{50%{opacity:.55}}@media(max-width:767.98px){.desktop-table{display:none}.mobile-list{display:grid;gap:.75rem}.fq-tabs .nav-item{width:50%}.fq-tabs .nav-link{width:100%;font-size:.78rem}.fq-stat{padding:.7rem}.fq-actions .btn,.fq-actions form{flex:1 1 calc(50% - .35rem)}.fq-actions form .btn{width:100%}.modal-dialog{margin:.25rem}.modal-content{min-height:calc(100vh - .5rem)}}
</style>

<div class="finance-page py-4" dir="rtl">
<div class="container">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div><h4 class="mb-1">صف تأیید مالی</h4><div class="fq-muted small">مرکز بررسی، مشاهده و تصمیم‌گیری مالی اسناد فروش</div></div>
  </div>
  @foreach(['success'=>'success','error'=>'danger','warning'=>'warning'] as $key=>$type) @if(session($key))<div class="alert alert-{{ $type }}">{{ session($key) }}</div>@endif @endforeach
  @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-3"><div class="fq-stat"><div class="fq-muted small">در انتظار تأیید مالی</div><div class="value">{{ number_format($pendingCount) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="fq-stat"><div class="fq-muted small">رزرو منقضی‌شده</div><div class="value text-danger">{{ number_format($expiredCount) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="fq-stat"><div class="fq-muted small">نیازمند تأیید مجدد</div><div class="value">{{ number_format($reapprovalCount) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="fq-stat"><div class="fq-muted small">نزدیک به انقضای رزرو</div><div class="value text-warning">{{ number_format($stats['expiring_soon'] ?? 0) }}</div></div></div>
  </div>

  <ul class="nav nav-pills fq-tabs gap-2 mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $activeTab==='preinvoices'?'active':'' }}" href="{{ route('preinvoice.draft.index',['tab'=>'preinvoices']) }}">پیش‌فاکتورهای منتظر تأیید مالی <span class="badge text-bg-light ms-1">{{ number_format($pendingCount) }}</span></a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab==='expired'?'active':'' }}" href="{{ route('preinvoice.draft.index',['tab'=>'expired']) }}">رزروهای منقضی‌شده <span class="badge text-bg-light ms-1">{{ number_format($expiredCount) }}</span></a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab==='reapprovals'?'active':'' }}" href="{{ route('preinvoice.draft.index',['tab'=>'reapprovals']) }}">نیازمند تأیید مجدد <span class="badge text-bg-light ms-1">{{ number_format($reapprovalCount) }}</span></a></li>
  </ul>

  @if($activeTab==='preinvoices')
    <div class="fq-card overflow-hidden"><div class="desktop-table table-responsive"><table class="table fq-table align-middle mb-0"><thead class="table-light"><tr><th>شماره/مشتری</th><th>فروشنده</th><th>اقلام</th><th>مبلغ نهایی</th><th>شرایط پرداخت</th><th>تاریخ ثبت</th><th>رزرو</th><th class="text-end">عملیات</th></tr></thead><tbody>
      @forelse($orders as $o) @include('preinvoice.partials.finance-row',['o'=>$o,'mobile'=>false]) @empty <tr><td colspan="8"><div class="empty-state">در حال حاضر پیش‌فاکتوری در انتظار تأیید مالی نیست.</div></td></tr> @endforelse
    </tbody></table></div><div class="mobile-list p-2">@forelse($orders as $o) @include('preinvoice.partials.finance-row',['o'=>$o,'mobile'=>true]) @empty <div class="empty-state">در حال حاضر پیش‌فاکتوری در انتظار تأیید مالی نیست.</div> @endforelse</div><div class="p-2">{{ $orders->links() }}</div></div>
  @elseif($activeTab==='expired')
    <div class="fq-card overflow-hidden"><div class="desktop-table table-responsive"><table class="table fq-table align-middle mb-0"><thead class="table-light"><tr><th>شماره/مشتری</th><th>فروشنده</th><th>اقلام</th><th>مبلغ نهایی</th><th>تاریخ انقضا</th><th>علت</th><th class="text-end">عملیات</th></tr></thead><tbody>
      @forelse($expiredOrders as $o)<tr class="table-danger"><td><a class="fw-bold" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">{{ $o->uuid }}</a><div>{{ $o->customer_name ?: '—' }}</div><div class="small fq-muted">{{ $o->customer_mobile ?: 'بدون موبایل' }}</div></td><td>{{ $o->creator?->name ?? '—' }}</td><td>{{ number_format($o->items->sum('quantity')) }}</td><td>{{ $rial($o->total_price) }}</td><td>{{ $dateFa($o->stock_released_at ?? $o->stock_frozen_until) }}</td><td>انقضای زمان رزرو موجودی</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">مشاهده</a></td></tr>@empty <tr><td colspan="7"><div class="empty-state">پیش‌فاکتور منقضی‌شده‌ای وجود ندارد.</div></td></tr> @endforelse
    </tbody></table></div><div class="mobile-list p-2">@forelse($expiredOrders as $o)<div class="doc-mobile border-danger"><div class="d-flex justify-content-between"><a class="fw-bold" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">{{ $o->uuid }}</a><span>{{ $rial($o->total_price) }}</span></div><div class="small fq-muted my-2">{{ $o->customer_name ?: '—' }} | {{ $dateFa($o->stock_released_at ?? $o->stock_frozen_until) }}</div><div class="small mb-2">انقضای زمان رزرو موجودی</div><a class="btn btn-sm btn-outline-primary w-100" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">مشاهده</a></div>@empty <div class="empty-state">پیش‌فاکتور منقضی‌شده‌ای وجود ندارد.</div> @endforelse</div><div class="p-2">{{ $expiredOrders->links() }}</div></div>
  @else
    <div class="fq-card overflow-hidden"><div class="desktop-table table-responsive"><table class="table fq-table align-middle mb-0"><thead class="table-light"><tr><th>شماره فاکتور</th><th>مشتری</th><th>فروشنده/تغییر‌دهنده</th><th>مبلغ قبلی</th><th>مبلغ جدید</th><th>اختلاف</th><th>تاریخ/توضیح تغییر</th><th class="text-end">عملیات</th></tr></thead><tbody>
    @forelse($financeReapprovalInvoices as $invoice) @php($diff=0) <tr><td><a href="{{ route('invoices.show',$invoice->uuid) }}">{{ $invoice->uuid }}</a></td><td>{{ $invoice->customer_name ?: '—' }}</td><td>{{ $invoice->preinvoiceOrder?->creator?->name ?? $invoice->statusChangedByUser?->name ?? '—' }}</td><td class="text-muted">—</td><td>{{ $rial($invoice->total) }}</td><td class="text-muted">—</td><td><div>{{ $fmtDate($invoice->items_updated_at) }}</div><div class="small text-muted text-break">{{ $invoice->collection_note ?: '—' }}</div></td><td class="text-end"><div class="fq-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show',$invoice->uuid) }}">مشاهده فاکتور</a><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#invoiceChanges{{ $invoice->id }}">مشاهده تغییرات</button><form method="POST" action="{{ route('finance.invoices.reapprove',$invoice->uuid) }}" data-guard-submit>@csrf<button class="btn btn-sm btn-success">تأیید تغییرات و ارسال به صف ارسال</button></form><button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#invoiceReturn{{ $invoice->id }}">ارجاع برای اصلاح</button><a class="btn btn-sm btn-outline-secondary" href="{{ route('invoices.print',$invoice->uuid) }}">چاپ</a></div></td></tr> @empty <tr><td colspan="8"><div class="empty-state">هیچ فاکتوری نیازمند تأیید مجدد مالی نیست.</div></td></tr> @endforelse
    </tbody></table></div><div class="mobile-list p-2">@forelse($financeReapprovalInvoices as $invoice)<div class="doc-mobile"><div class="d-flex justify-content-between"><a class="fw-bold" href="{{ route('invoices.show',$invoice->uuid) }}">{{ $invoice->uuid }}</a><span>{{ $rial($invoice->total) }}</span></div><div class="small fq-muted mb-2">{{ $invoice->customer_name ?: '—' }} | {{ $fmtDate($invoice->items_updated_at) }}</div><div class="fq-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show',$invoice->uuid) }}">مشاهده فاکتور</a><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#invoiceChanges{{ $invoice->id }}">مشاهده تغییرات</button><form method="POST" action="{{ route('finance.invoices.reapprove',$invoice->uuid) }}" data-guard-submit>@csrf<button class="btn btn-sm btn-success">تأیید تغییرات</button></form><button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#invoiceReturn{{ $invoice->id }}">ارجاع</button></div></div>@empty <div class="empty-state">هیچ فاکتوری نیازمند تأیید مجدد مالی نیست.</div>@endforelse</div><div class="p-2">{{ $financeReapprovalInvoices->links() }}</div></div>
  @endif
</div></div>

@include('preinvoice.partials.finance-modals')

<script>
document.querySelectorAll('[data-guard-submit]').forEach(f=>f.addEventListener('submit',()=>{const b=f.querySelector('button[type="submit"],button:not([type])'); if(b){b.disabled=true;b.dataset.oldText=b.textContent;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> در حال ارسال';}}));
function fmt(sec){sec=Math.max(0,Math.floor(sec));const h=String(Math.floor(sec/3600)).padStart(2,'0'),m=String(Math.floor(sec%3600/60)).padStart(2,'0'),s=String(sec%60).padStart(2,'0');return `${h}:${m}:${s}`}
function tick(){let expired=false;document.querySelectorAll('[data-reservation-timer]').forEach(el=>{const exp=el.dataset.expiresAt;if(!exp){el.querySelector('.reservation-countdown').textContent=el.dataset.label||'رزرو بدون محدودیت زمانی';return}const total=parseInt(el.dataset.totalSeconds||'0'),left=Math.floor((new Date(exp)-new Date())/1000),c=el.querySelector('.reservation-countdown'),badge=el.querySelector('.reservation-status'),bar=el.querySelector('.timer-progress span');c.textContent=left>0?fmt(left):'رزرو منقضی شده';c.className='reservation-countdown '+(left<=0?'timer-expired':left<900?'timer-red':left<3600?'timer-yellow':'timer-green');if(bar&&total>0)bar.style.width=Math.max(0,Math.min(100,left/total*100))+'%';if(left<=0){expired=true; if(badge){badge.textContent='منقضی‌شده';badge.className='badge text-bg-danger reservation-status'} el.closest('[data-reservation-row]')?.querySelectorAll('[data-disable-on-expire]').forEach(b=>b.disabled=true);}}); if(expired&&!window.__fqRefresh){window.__fqRefresh=true;setTimeout(()=>location.reload(),5000)}} tick(); setInterval(tick,1000);
document.querySelectorAll('[data-other-toggle]').forEach(sel=>sel.addEventListener('change',()=>{const target=document.getElementById(sel.dataset.otherToggle); if(target) target.required=sel.value==='سایر';}));
</script>
@endsection
