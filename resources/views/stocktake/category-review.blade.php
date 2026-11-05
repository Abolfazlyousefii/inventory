@extends('layouts.app')
@section('title', 'بررسی سند انبارگردانی دسته')
@section('content')
@php
    $fa = fn($n) => strtr(number_format((int) $n), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
@endphp
<div class="container-fluid py-3" dir="rtl" style="max-width:1450px">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <div class="text-muted small">کنترل موجودی انبار مرکزی</div>
            <h4 class="mb-1">بررسی شمارش {{ $document->scope_name }}</h4>
            <p class="text-muted mb-0">یک سند برای کل محدوده · {{ $document->document_number }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if($document->status === 'draft')
                <a class="btn btn-outline-primary" href="{{ route('stock-count-documents.edit', $document) }}">ادامه و ویرایش شمارش</a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('stocktake.index') }}">آرشیو انبارگردانی</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 gap-lg-4">
            <span>وضعیت: <strong>{{ $document->status === 'draft' ? 'پیش‌نویس' : ($document->status === 'finalized' ? 'اعمال‌شده' : 'لغوشده') }}</strong></span>
            <span>تنوع‌های شمارش‌شده: <strong>{{ $fa($countedItems->count()) }}</strong></span>
            <span>شمارش صریح صفر: <strong>{{ $fa($document->zeroed_count) }}</strong></span>
            <span>جمع شمارش واقعی: <strong>{{ $fa($document->total_actual) }}</strong></span>
            <span>جمع موجودی فیزیکی مبنا: <strong>{{ $fa($document->total_before) }}</strong></span>
            <span>افزایش: <strong>{{ $fa($document->total_increase) }}</strong></span>
            <span>کاهش: <strong>{{ $fa($document->total_decrease) }}</strong></span>
        </div>
    </div>

    @if($document->status === 'draft')
        <div class="alert alert-warning">
            برای ثبت نهایی این انبارگردانی، همهٔ تنوع‌های واجد شرایط محدوده باید صریحاً شمارش شده باشند؛ عدد ۰ شمارش معتبر است.
            @if($missingCount > 0)
                <strong>{{ $fa($missingCount) }} تنوع هنوز شمارش نشده است. برای تکمیل شمارش به ویرایش سند برگردید.</strong>
            @else
                <strong>همهٔ تنوع‌های محدوده تعیین تکلیف شده‌اند؛ اکنون می‌توانید نتیجه را تأیید کنید.</strong>
            @endif
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header fw-bold">تنوع‌های شمارش‌شده و اختلاف موجودی</div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>کالا</th>
                        <th>تنوع</th>
                        <th>کد کالا</th>
                        <th>موجودی فیزیکی مبنا</th>
                        <th>رزرو فعال</th>
                        <th>شمارش واقعی</th>
                        <th>اختلاف</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($countedItems as $item)
                        @php $difference = (int) $item->actual_quantity - (int) $item->expected_physical_at_start; @endphp
                        <tr>
                            <td>{{ $item->product_name_snapshot }}</td>
                            <td>{{ $item->variant_name_snapshot }}</td>
                            <td><bdi>{{ $item->sku_snapshot }}</bdi></td>
                            <td>{{ $fa($item->expected_physical_at_start) }}</td>
                            <td>{{ $fa($item->reserved_at_start) }}</td>
                            <td>
                                <strong>{{ $fa($item->actual_quantity) }}</strong>
                                @if((int) $item->actual_quantity === 0)
                                    <span class="badge text-bg-warning">صفر صریح</span>
                                @endif
                            </td>
                            <td dir="ltr">{{ $difference > 0 ? '+' : '' }}{{ $fa($difference) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center p-4">هنوز هیچ تنوعی با عدد صریح شمارش نشده است؛ ثبت نهایی در دسترس نیست.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($document->status === 'draft')
        @if($countedItems->isNotEmpty() && $missingCount === 0)
            <form id="categoryFinalizeForm" class="card"
                  data-document-id="{{ $document->id }}"
                  data-user-id="{{ auth()->id() }}"
                  data-review-token="{{ $reviewToken }}"
                  data-zero-count="{{ $document->zeroed_count }}"
                  data-total-count="{{ $countedItems->count() }}"
                  data-finalize-url="{{ route('stock-count-documents.category-document.finalize', $document) }}">
                @csrf
                <div class="card-body">
                    <p class="mb-3">
                        با تأیید، شمارش کامل <strong>{{ $fa($countedItems->count()) }} تنوع محدوده</strong>
                        شامل <strong>{{ $fa($document->zeroed_count) }} تنوع با مقدار صفر</strong> اعمال می‌شود.
                        پس از بررسی، موجودی انبار مرکزی بر اساس این شمارش اصلاح خواهد شد.
                    </p>
                    <div id="categoryFinalizeStatus" role="status" aria-live="polite" class="mb-2"></div>
                    <button type="button" id="categoryFinalizeButton" class="btn btn-success">تأیید شمارش کامل و اعمال موجودی</button>
                </div>
            </form>
            <script>{!! file_get_contents(public_path('js/stocktake-category-finalize.js')) !!}</script>
        @else
            <div class="alert alert-secondary">برای فعال‌شدن ثبت نهایی، تمام تنوع‌های محدوده را با مقدار واقعی یا صفر مشخص و پیش‌نویس را ذخیره کنید. {{ $fa($missingCount) }} تنوع هنوز شمارش نشده است.</div>
        @endif
    @elseif($document->status === 'finalized')
        <div class="alert alert-success">ثبت نهایی این سند انجام شده است. سوابق و تعدیل‌های ثبت‌شده حفظ شده‌اند.</div>
    @endif
</div>
@endsection
