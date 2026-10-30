@extends('layouts.app')
@section('title', 'بررسی سند انبارگردانی دسته')
@section('content')
@php $fa = fn($n) => strtr(number_format((int)$n), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); @endphp
<div class="container-fluid py-3" dir="rtl" style="max-width:1400px">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h4>بررسی شمارش {{ $document->scope_name }}</h4><p class="text-muted mb-0">یک سند برای همهٔ کالاهای دسته و زیردسته‌های آن · {{ $document->document_number }}</p></div>
  <div class="d-flex gap-2">@if($document->status==='draft')<a class="btn btn-outline-primary" href="{{ route('stock-count-documents.edit',$document) }}">ادامه و ویرایش همین سند</a>@endif<a class="btn btn-outline-secondary" href="{{ route('stocktake.index') }}">آرشیو انبارگردانی</a></div>
 </div>
 <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4">
  <span>وضعیت: <strong>{{ $document->status==='draft'?'پیش‌نویس':($document->status==='finalized'?'نهایی‌شده':'لغوشده') }}</strong></span>
  <span>کل تنوع‌ها: <strong>{{ $fa($document->variants_count) }}</strong></span><span>ردیف‌های دارای موجودی: <strong>{{ $fa($positiveItems->count()) }}</strong></span>
  <span>ردیف‌های صفر: <strong>{{ $fa($document->zeroed_count) }}</strong></span><span>جمع شمارش واقعی: <strong>{{ $fa($document->total_actual) }}</strong></span>
  <span>افزایش: <strong>{{ $fa($document->total_increase) }}</strong></span><span>کاهش: <strong>{{ $fa($document->total_decrease) }}</strong></span>
 </div></div>
 <div class="alert alert-warning">برای تطبیق با برگه، فقط ردیف‌های دارای عدد مثبت نمایش داده می‌شوند. <strong>{{ $fa($document->zeroed_count) }} ردیفِ صفر هم جزو همین سند است</strong> و با ثبت نهایی، موجودی آن‌ها نیز صفر اعمال می‌شود. ذخیرهٔ پیش‌نویس موجودی را تغییر نمی‌دهد.</div>
 <div class="card mb-3"><div class="card-header">کالاهایی که موجودی واقعی دارند</div><div class="card-body table-responsive">
  <table class="table table-bordered table-striped align-middle"><thead><tr><th>کالا</th><th>تنوع</th><th>کد کالا</th><th>موجودی قبل</th><th>رزرو</th><th>شمارش واقعی</th><th>اختلاف</th></tr></thead><tbody>
   @forelse($positiveItems as $item)<tr><td>{{ $item->product_name_snapshot }}</td><td>{{ $item->variant_name_snapshot }}</td><td>{{ $item->sku_snapshot }}</td><td>{{ $fa($item->expected_physical_at_start) }}</td><td>{{ $fa($item->reserved_at_start) }}</td><td><strong>{{ $fa($item->actual_quantity) }}</strong></td><td dir="ltr">{{ $fa($item->actual_quantity-$item->expected_physical_at_start) }}</td></tr>
   @empty<tr><td colspan="7" class="text-center p-4">هیچ ردیف مثبتی ثبت نشده است؛ موجودی واقعی همهٔ ردیف‌های این سند صفر است.</td></tr>@endforelse
  </tbody></table>
 </div></div>
 @if($document->status==='draft')
 <form id="categoryFinalizeForm" class="card" data-document-id="{{ $document->id }}" data-user-id="{{ auth()->id() }}" data-review-token="{{ $reviewToken }}" data-zero-count="{{ $document->zeroed_count }}" data-total-count="{{ $document->variants_count }}" data-finalize-url="{{ route('stock-count-documents.category-document.finalize',$document) }}">@csrf
  <div class="card-body"><p>برگهٔ شمارش و ردیف‌های مثبت را بررسی کنید؛ تأیید زیر تمام ردیف‌های همین سند، شامل صفرها را یکجا اعمال می‌کند. دستهٔ بسته‌شده تا بازگشایی صریح بسته می‌ماند.</p>
   <div id="categoryFinalizeStatus" role="status" aria-live="polite" class="mb-2"></div><button type="button" id="categoryFinalizeButton" class="btn btn-success">تأیید شمارش و اعمال کل سند</button>
  </div>
 </form>
 <script>{!! file_get_contents(public_path('js/stocktake-category-finalize.js')) !!}</script>
 @endif
</div>
@endsection
