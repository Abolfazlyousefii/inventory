@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $dateFa = fn($d) => $d ? Jalalian::fromDateTime($d)->format('Y/m/d H:i') : '—';
@endphp

@section('content')
<style>
  .finance-queue{background:#fff}.fq-head h4{font-size:1.05rem;font-weight:700}.fq-muted{color:#64748b}.fq-tabs{gap:.35rem}.fq-tabs .nav-link{font-size:.82rem;padding:.45rem .75rem;border:1px solid #e2e8f0;color:#334155;background:#fff}.fq-tabs .nav-link.active{background:#f1f5f9;color:#0f172a;border-color:#cbd5e1}.fq-card{border:1px solid #e5e7eb;border-radius:.7rem;background:#fff;overflow:hidden}.fq-table{margin:0;table-layout:fixed}.fq-table th{font-size:.75rem;font-weight:600;color:#475569;background:#f8fafc;white-space:nowrap}.fq-table td{font-size:.82rem;vertical-align:middle;color:#1f2937}.fq-doc-link{font-weight:700;text-decoration:none}.fq-timer{display:inline-flex;flex-direction:column;gap:.1rem;min-width:82px}.fq-timer-value{direction:ltr;unicode-bidi:plaintext;font-variant-numeric:tabular-nums;font-weight:700}.fq-timer-label{font-size:.68rem;color:#64748b}.fq-green{color:#15803d}.fq-yellow{color:#a16207}.fq-red{color:#b91c1c}.mobile-list{display:none}.fq-mobile-card{border:1px solid #e5e7eb;border-radius:.7rem;padding:.75rem;background:#fff}.fq-mobile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.45rem}.fq-mobile-grid small{display:block;color:#64748b}.empty-state{text-align:center;color:#64748b;padding:2rem}.table-responsive{overflow-x:visible}@media(max-width:767.98px){.desktop-table{display:none}.mobile-list{display:grid;gap:.6rem}.fq-tabs .nav-link{font-size:.75rem;padding:.4rem}.fq-mobile-actions .btn{width:100%}.container{max-width:100%;overflow-x:hidden}}
</style>

<div class="container py-4 finance-queue" dir="rtl">
  <div class="fq-head d-flex justify-content-between align-items-start gap-2 mb-3 flex-wrap">
    <div><h4 class="mb-1">صف تأیید مالی</h4><div class="fq-muted small">لیست ساده اسناد مالی؛ تصمیم‌گیری فقط در صفحه مشاهده انجام می‌شود.</div></div>
  </div>

  @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger py-2"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

  <ul class="nav nav-pills fq-tabs mb-3">
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'pending' ? 'active' : '' }}" href="{{ route('preinvoice.draft.index', ['tab' => 'pending']) }}">پیش‌فاکتورهای منتظر تأیید ({{ number_format($pendingCount) }})</a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'expired' ? 'active' : '' }}" href="{{ route('preinvoice.draft.index', ['tab' => 'expired']) }}">منقضی‌شده‌ها ({{ number_format($expiredCount) }})</a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'reapproval' ? 'active' : '' }}" href="{{ route('preinvoice.draft.index', ['tab' => 'reapproval']) }}">نیازمند تأیید مجدد ({{ number_format($reapprovalCount) }})</a></li>
  </ul>

  @if($activeTab === 'pending')
    <div class="fq-card">
      <div class="desktop-table table-responsive"><table class="table fq-table align-middle"><thead><tr><th>شماره پیش‌فاکتور</th><th>مشتری</th><th>فروشنده</th><th>تعداد اقلام</th><th>مبلغ نهایی</th><th>تاریخ ثبت</th><th>زمان باقی‌مانده رزرو</th><th class="text-end">عملیات</th></tr></thead><tbody>
        @forelse($pendingOrders as $order)
          <tr><td><a class="fq-doc-link" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">{{ $order->uuid }}</a></td><td>{{ $order->customer_name ?: '—' }}</td><td>{{ $order->creator?->name ?? '—' }}</td><td>{{ number_format((int) $order->items->sum('quantity')) }}</td><td>{{ $rial($order->total_price) }}</td><td>{{ $dateFa($order->created_at) }}</td><td>@include('preinvoice.partials.simple-reservation-timer', ['order' => $order])</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">مشاهده</a></td></tr>
        @empty<tr><td colspan="8"><div class="empty-state">در حال حاضر پیش‌فاکتوری در انتظار تأیید مالی نیست.</div></td></tr>@endforelse
      </tbody></table></div>
      <div class="mobile-list p-2">@forelse($pendingOrders as $order)<div class="fq-mobile-card"><div class="d-flex justify-content-between gap-2 mb-2"><a class="fq-doc-link" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">{{ $order->uuid }}</a><span>{{ $rial($order->total_price) }}</span></div><div class="fq-mobile-grid small mb-2"><div><small>مشتری</small>{{ $order->customer_name ?: '—' }}</div><div><small>فروشنده</small>{{ $order->creator?->name ?? '—' }}</div><div><small>اقلام</small>{{ number_format((int) $order->items->sum('quantity')) }}</div><div><small>ثبت</small>{{ $dateFa($order->created_at) }}</div></div>@include('preinvoice.partials.simple-reservation-timer', ['order' => $order])<div class="fq-mobile-actions mt-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">مشاهده</a></div></div>@empty<div class="empty-state">در حال حاضر پیش‌فاکتوری در انتظار تأیید مالی نیست.</div>@endforelse</div>
      <div class="p-2">{{ $pendingOrders->links() }}</div>
    </div>
  @elseif($activeTab === 'expired')
    <div class="fq-card"><div class="desktop-table table-responsive"><table class="table fq-table align-middle"><thead><tr><th>شماره پیش‌فاکتور</th><th>مشتری</th><th>فروشنده</th><th>مبلغ</th><th>زمان انقضا</th><th>علت</th><th class="text-end">عملیات</th></tr></thead><tbody>
      @forelse($expiredOrders as $order)<tr><td><a class="fq-doc-link" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">{{ $order->uuid }}</a></td><td>{{ $order->customer_name ?: '—' }}</td><td>{{ $order->creator?->name ?? '—' }}</td><td>{{ $rial($order->total_price) }}</td><td>{{ $dateFa($order->stock_released_at ?? $order->stock_frozen_until) }}</td><td>انقضای زمان رزرو موجودی</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">مشاهده</a></td></tr>@empty<tr><td colspan="7"><div class="empty-state">پیش‌فاکتور منقضی‌شده‌ای وجود ندارد.</div></td></tr>@endforelse
    </tbody></table></div><div class="mobile-list p-2">@forelse($expiredOrders as $order)<div class="fq-mobile-card"><div class="d-flex justify-content-between"><a class="fq-doc-link" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">{{ $order->uuid }}</a><span>{{ $rial($order->total_price) }}</span></div><div class="small fq-muted my-2">{{ $order->customer_name ?: '—' }} | {{ $dateFa($order->stock_released_at ?? $order->stock_frozen_until) }}</div><div class="small mb-2">انقضای زمان رزرو موجودی</div><a class="btn btn-sm btn-outline-primary w-100" href="{{ route('preinvoice.draft.finance', $order->uuid) }}">مشاهده</a></div>@empty<div class="empty-state">پیش‌فاکتور منقضی‌شده‌ای وجود ندارد.</div>@endforelse</div><div class="p-2">{{ $expiredOrders->links() }}</div></div>
  @else
    <div class="fq-card"><div class="desktop-table table-responsive"><table class="table fq-table align-middle"><thead><tr><th>شماره فاکتور</th><th>مشتری</th><th>فروشنده</th><th>مبلغ فعلی</th><th>پرداخت‌شده</th><th>مانده</th><th>تاریخ تغییر</th><th>تغییر‌دهنده</th><th class="text-end">عملیات</th></tr></thead><tbody>
      @forelse($financeReapprovalInvoices as $invoice) @php($paid=(int)($invoice->paid_amount ?? 0)) @php($remaining=max((int)$invoice->total-$paid,0))<tr><td><a class="fq-doc-link" href="{{ route('invoices.show', $invoice->uuid) }}">{{ $invoice->uuid }}</a></td><td>{{ $invoice->customer_name ?: '—' }}</td><td>{{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</td><td>{{ $rial($invoice->total) }}</td><td>{{ $rial($paid) }}</td><td>{{ $rial($remaining) }}</td><td>{{ $dateFa($invoice->items_updated_at) }}</td><td>{{ $invoice->statusChangedByUser?->name ?? '—' }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show', $invoice->uuid) }}">مشاهده</a></td></tr>@empty<tr><td colspan="9"><div class="empty-state">هیچ فاکتوری نیازمند تأیید مجدد مالی نیست.</div></td></tr>@endforelse
    </tbody></table></div><div class="mobile-list p-2">@forelse($financeReapprovalInvoices as $invoice) @php($paid=(int)($invoice->paid_amount ?? 0))<div class="fq-mobile-card"><div class="d-flex justify-content-between"><a class="fq-doc-link" href="{{ route('invoices.show', $invoice->uuid) }}">{{ $invoice->uuid }}</a><span>{{ $rial($invoice->total) }}</span></div><div class="fq-mobile-grid small my-2"><div><small>مشتری</small>{{ $invoice->customer_name ?: '—' }}</div><div><small>فروشنده</small>{{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</div><div><small>پرداخت‌شده</small>{{ $rial($paid) }}</div><div><small>تغییر</small>{{ $dateFa($invoice->items_updated_at) }}</div></div><a class="btn btn-sm btn-outline-primary w-100" href="{{ route('invoices.show', $invoice->uuid) }}">مشاهده</a></div>@empty<div class="empty-state">هیچ فاکتوری نیازمند تأیید مجدد مالی نیست.</div>@endforelse</div><div class="p-2">{{ $financeReapprovalInvoices->links() }}</div></div>
  @endif
</div>

<script>
function fqFmt(sec){sec=Math.max(0,Math.floor(sec));return [Math.floor(sec/3600),Math.floor(sec%3600/60),sec%60].map(v=>String(v).padStart(2,'0')).join(':')}
function fqTick(){document.querySelectorAll('[data-simple-timer]').forEach(el=>{const exp=el.dataset.expiresAt,val=el.querySelector('[data-timer-value]');if(!exp){val.textContent='بدون انقضا';return;}const left=Math.floor((new Date(exp)-new Date())/1000);val.textContent=left>0?fqFmt(left):'منقضی شد';val.className='fq-timer-value '+(left<900?'fq-red':left<3600?'fq-yellow':'fq-green');});}
fqTick();setInterval(fqTick,1000);
</script>
@endsection
