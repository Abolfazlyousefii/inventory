@extends('layouts.app')
@section('content')
<style>
.sc-review{max-width:1460px;margin:0 auto;padding:12px 16px 60px;direction:rtl}
.sc-review .sc-card{background:#fff;border:1px solid #dce6ef;border-radius:15px;padding:18px;margin-bottom:14px;box-shadow:0 5px 20px #21374a0a}
.sc-review .sc-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sc-review .sc-muted{font-size:.83rem;color:#64748b}
.sc-review .sc-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.sc-review .sc-metric{background:#f7fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px}
.sc-review .sc-metric strong{display:block;font-size:1.15rem}
.sc-review .sc-table{width:100%;border-collapse:collapse;font-size:.84rem}
.sc-review .sc-table th{background:#edf5f8;color:#214358}
.sc-review .sc-table th,.sc-review .sc-table td{border:1px solid #e0e7ee;padding:9px 8px;text-align:right;vertical-align:middle}
.sc-review .sc-table tr:nth-child(even){background:#fafcfd}
.sc-review .sc-n{white-space:nowrap;font-variant-numeric:tabular-nums}
.sc-review .sc-warning{color:#a34d1f;font-weight:750}
.sc-review .sc-success{color:#146c48;font-weight:750}
.sc-review .sc-actions{position:sticky;bottom:0;background:#fffffff5;border:1px solid #d8e4ec;border-radius:12px;box-shadow:0 -4px 20px #183b5117;padding:13px;z-index:10}
@media(max-width:720px){.sc-review{padding:8px}.sc-review .sc-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.sc-review .sc-table-wrap{overflow-x:auto}.sc-review .sc-table{min-width:940px}}
@media print{.sc-review .sc-actions,.sc-review .sc-no-print{display:none!important}.sc-review{padding:0}.sc-review .sc-card{box-shadow:none;break-inside:avoid}.sc-review .sc-table thead{display:table-header-group}}
</style>
<div class="sc-review">
 <div class="sc-card sc-header">
  <div>
   <h4 class="mb-1">بررسی مغایرت‌های انبارگردانی</h4>
   <div class="sc-muted">سند {{ $document->document_number }} | دسته {{ $document->category?->name }} | وضعیت: پیش‌نویس</div>
  </div>
  <div class="d-flex gap-2 sc-no-print">
   <a class="btn btn-outline-primary" href="{{ route('stock-count-documents.category-bulk.resume',$document) }}">بازگشت و اصلاح شمارش</a>
   <button class="btn btn-outline-secondary" type="button" onclick="window.print()">چاپ گزارش بررسی</button>
  </div>
 </div>
 <div class="sc-card">
  <div class="sc-metrics">
   <div class="sc-metric"><div>کل تنوع‌های سند</div><strong>{{ $items->count() }}</strong></div>
   <div class="sc-metric"><div>شمارش‌شده</div><strong>{{ $items->count()-$missingCount }}</strong></div>
   <div class="sc-metric"><div>شمارش‌نشده</div><strong class="{{ $missingCount ? 'sc-warning':'' }}">{{ $missingCount }}</strong></div>
   <div class="sc-metric"><div>واقعی کمتر از رزرو</div><strong class="{{ $belowReserved ? 'sc-warning':'' }}">{{ $belowReserved }}</strong></div>
  </div>
  <p class="sc-muted mt-3 mb-0">این مقایسه از <strong>پیش‌نویس ذخیره‌شده</strong> خوانده می‌شود. موجودی سیستم و رزرو مربوط به شروع سند است؛ هنگام اعمال، وضعیت جاری دوباره زیر قفل تراکنش بررسی می‌شود.</p>
 </div>
 @if($errors->has('finalize'))<div class="alert alert-danger" role="alert">{{ $errors->first('finalize') }}</div>@endif
 @if($missingCount || $belowReserved)
  <div class="alert alert-warning" role="alert">تأیید نهایی مجاز نیست. {{ $missingCount }} تنوع هنوز شمارش نشده و {{ $belowReserved }} تنوع موجودی واقعی کمتر از رزرو دارد. به فرم برگردید و شمارش‌ها را اصلاح کنید.</div>
 @endif
 <div class="sc-card sc-table-wrap">
  <table class="sc-table" aria-label="لیست تطبیق تمام تنوع‌های انبارگردانی">
   <thead><tr><th>ردیف</th><th>نام کالا</th><th>تنوع / کد</th><th>آزاد اولیه</th><th>رزرو اولیه</th><th>فیزیکی مورد انتظار</th><th>شمارش واقعی</th><th>اختلاف</th><th>آزاد پس از اعمال</th></tr></thead>
   <tbody>
    @foreach($items as $item)
    @php
      $counted = $item->actual_quantity !== null;
      $delta = $counted ? (int)$item->actual_quantity-(int)$item->expected_physical_at_start : null;
      $newAvailable = $counted ? (int)$item->actual_quantity-(int)$item->reserved_at_start : null;
    @endphp
    <tr>
     <td>{{ $loop->iteration }}</td>
     <td><strong>{{ $item->product_name_snapshot }}</strong></td>
     <td>{{ $item->variant_name_snapshot }}<div class="sc-muted" dir="ltr">{{ $item->sku_snapshot }}</div></td>
     <td class="sc-n">{{ $item->system_available_at_start }}</td>
     <td class="sc-n">{{ $item->reserved_at_start }}</td>
     <td class="sc-n">{{ $item->expected_physical_at_start }}</td>
     <td class="sc-n {{ !$counted ? 'sc-warning':'' }}">{{ $counted ? $item->actual_quantity : 'شمارش نشده' }}</td>
     <td class="sc-n {{ $delta === null ? '' : ($delta < 0 ? 'sc-warning': ($delta > 0 ? 'sc-success' : '')) }}">{{ $delta === null ? '—' : ($delta > 0 ? '+' : '').$delta }}</td>
     <td class="sc-n {{ $newAvailable !== null && $newAvailable < 0 ? 'sc-warning':'' }}">{{ $newAvailable ?? '—' }}</td>
    </tr>
    @endforeach
   </tbody>
  </table>
 </div>
 <div class="sc-actions">
  @if(!$canFinalize)
   <p class="sc-warning m-0">برای ثبت نهایی ابتدا تمام تنوع‌ها را شمارش کنید. تأیید و اعمال موجودی فقط برای مدیر مجاز فعال می‌شود.</p>
  @else
   <form method="post" action="{{ route('stock-count-documents.category-bulk.finalize',$document) }}" id="scApproveForm">
    @csrf @method('PATCH')
    <input type="hidden" name="review_token" value="{{ $reviewToken }}">
    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
     <label class="m-0"><input id="scReviewed" type="checkbox" required> همه ردیف‌ها و اختلاف‌ها را بررسی کرده‌ام و با اعمال موجودی مرکزی موافقم.</label>
     <button type="submit" id="scApprove" class="btn btn-success" disabled>تأیید نهایی و اعمال یک‌جای موجودی</button>
    </div>
   </form>
  @endif
 </div>
</div>
<script>
const approveCheck=document.getElementById('scReviewed');
const approveButton=document.getElementById('scApprove');
approveCheck?.addEventListener('change',()=>{approveButton.disabled=!approveCheck.checked});
document.getElementById('scApproveForm')?.addEventListener('submit',event=>{
 if(!approveCheck.checked||!window.confirm('آخرین تأیید: تمام موجودی‌های این سند به‌صورت تراکنشی اعمال شوند؟')) event.preventDefault();
 else approveButton.disabled=true;
});
</script>
@endsection
