@extends('layouts.app')
@section('title', 'گردش موجودی '.$product->name)
@push('styles')<link rel="stylesheet" href="{{ asset('css/product-stock-ledger.css') }}?v=20261114-overview">@endpush
@section('content')
@php
$fa=fn($value)=>strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$n=fn($value)=>$fa(number_format((int)$value));
$reasons=['sales_return_correction'=>'اصلاح برگشت از فروش','purchase_return'=>'برگشت از خرید','purchase_return_restore'=>'بازگشت پس از اصلاح خرید','purchase'=>'خرید کالا','sale'=>'فروش کالا','return'=>'مرجوعی','sales_return'=>'برگشت از فروش','transfer'=>'انتقال بین انبارها','adjustment'=>'اصلاح موجودی','purchase_item_added'=>'افزودن به خرید','purchase_item_quantity_changed'=>'اصلاح تعداد خرید','purchase_item_removed'=>'حذف از خرید','invoice_adjustment_return'=>'دریافت پس از اصلاح فاکتور','invoice_cancel_return'=>'دریافت پس از لغو فاکتور','stocktake'=>'انبارگردانی','stock_count'=>'انبارگردانی','reservation'=>'رزرو کالا','reserve'=>'رزرو کالا','reservation_release'=>'آزادسازی رزرو'];
$statuses=['pending'=>'در انتظار دریافت','received'=>'دریافت‌شده','discrepancy'=>'دارای مغایرت','cancelled'=>'لغوشده','draft'=>'پیش‌نویس','applied'=>'سند اعمال‌شده','pending_warehouse'=>'در انتظار انبار'];
@endphp
<div class="psl">

	<header class="psl-heading">
		<div>
			<a class="psl-breadcrumb" href="{{ route('products.index') }}">کالاها</a>
			<span class="psl-breadcrumb-separator"> / </span>
			<span class="psl-eyebrow">گردش موجودی</span>

			<h1>{{ $product->name }}</h1>

			@include('partials.bundle-contents', [
				'productIsBundle' => $product->is_bundle,
				'productId' => $product->id
			])

			<p>{{ $selectedVariant?->variant_name ?: 'همهٔ مدل‌ها و رنگ‌های این کالا' }}</p>
		</div>

		<a class="psl-back" href="{{ route('products.index') }}">
			بازگشت به کالاها <span aria-hidden="true">←</span>
		</a>
	</header>

    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if(!$summary->projection_matches)
        <p class="alert alert-warning">نمایش موجودی مرکزی با انبار تطبیق ندارد؛ اختلاف: {{ $n(abs(($summary->cached_current ?? 0)-($summary->current ?? 0))) }} عدد. این اختلاف به‌تنهایی دلیل باگ نیست؛ اسناد مرتبط باید بررسی شوند.</p>
    @endif
    @foreach($overview['issues'] as $issue)
        <div class="alert alert-warning" role="alert"><strong>مغایرت مشخص در دریافت انبار:</strong> {{ $n($issue->accepted_quantity) }} عدد بابت {{ $issue->related_reference }} از رسید <a href="{{ $issue->source_url }}">{{ $issue->reference }}</a> اضافه شده، درحالی‌که موجودی فاکتور هنگام صدور کسر نشده بود. این سابقه نیازمند سند اصلاحی است؛ گزارش موجودی را تغییر نمی‌دهد.</div>
    @endforeach
    @if($bundleLedger)
    <section class="psl-overview" aria-label="موجودی بسته خودکار">
        <div class="psl-overview-heading"><div>
            <h2>موجودی بسته خودکار (محاسبه‌شده از قطعات اختصاصی)</h2>
            <p>این بسته در انبار به‌صورت محصول آماده نگهداری نمی‌شود؛ موجودی آن از سهمیهٔ محافظت‌شدهٔ قطعات محاسبه می‌شود.</p>
        </div></div>
        <div class="psl-overview-grid">
            <article class="psl-overview-card"><h3>بسته کامل از سهمیهٔ آزاد</h3><strong>{{ $n($bundleLedger['protected_packs']) }} <small>بسته</small></strong><p>ظرفیت قطعات آزاد پس از کسر رزروهای مصرف‌شده؛ مستقل از فعال‌بودن فروش</p></article>
            <article class="psl-overview-card"><h3>موجودی قابل فروش در ERP</h3><strong>{{ $n($bundleLedger['available_for_new_orders']) }} <small>بسته</small></strong><p>همان محاسبهٔ موجودی سبد در جست‌وجوی فروش/فاکتور؛ انتشار در سایت مستقل است</p></article>
            <article class="psl-overview-card"><h3>قطعات در سهمیهٔ آزاد</h3><strong>{{ $n($bundleLedger['protected_units']) }} <small>قطعه</small></strong><p>جمع واحدهای اختصاص‌یافته به این بسته؛ تعداد بسته نیست</p></article>
        </div>
        <p class="psl-overview-note">صفر بودن «موجودی فیزیکی انبارها» در پایین صفحه برای این نوع بسته طبیعی است. فعال‌سازی یا توقف فروش موجودی را اضافه یا کم نمی‌کند؛ تغییر سهمیه و رزروها جدا ثبت می‌شوند. این مقادیر را با موجودی عادی قطعات جمع نکنید.</p>
    </section>
    <section class="psl-book" aria-label="تاریخچه مدیریت و سهمیه بسته">
        <div class="psl-book-title"><div><strong>تاریخچهٔ مدیریت و سهمیهٔ بسته</strong><span>توقف/ادامهٔ فروش، آزادسازی قطعات و تغییر سهمیه از سوابق حسابرسی؛ بدون ثبت گردش فیزیکی ساختگی</span></div><span>{{ $n($bundleLedger['events']->total()) }} رویداد</span></div>
        <table>
            <thead><tr><th>زمان</th><th>عملیات</th><th>اثر بر موجودی عادی قطعات</th><th>ثبت و جزئیات</th></tr></thead>
            <tbody>
            @forelse($bundleLedger['events'] as $event)
                <tr class="psl-row" data-bundle-event-id="{{ $event->event_id }}">
                    <td class="psl-date" data-label="زمان"><span>{{ $fa(\App\Support\JalaliDate::date(\Carbon\Carbon::parse($event->created_at))) }}</span><small>{{ $fa(\Carbon\Carbon::parse($event->created_at)->format('H:i')) }}</small></td>
                    <td class="psl-event" data-label="عملیات"><strong>{{ $event->label }}</strong></td>
                    <td class="psl-change" data-label="اثر بر موجودی عادی">
                        @if($event->returned_units)<span class="psl-amount is-in">+{{ $n($event->returned_units) }} <small>قطعه بازگشت</small></span>@endif
                        @if($event->allocated_units)<span class="psl-amount is-out">−{{ $n($event->allocated_units) }} <small>قطعه اختصاص یافت</small></span>@endif
                        @if(!$event->returned_units && !$event->allocated_units)<span class="psl-document-caption">بدون جابه‌جایی قطعات</span>@endif
                    </td>
                    <td class="psl-source" data-label="ثبت و جزئیات">
                        <span class="psl-source-label">{{ $event->actor_name ?: 'سیستم / دستور مدیریتی' }}</span>
                        <details class="psl-row-details"><summary>جزئیات رویداد</summary><div>
                            <p>{{ $event->reason }}</p>
                            @foreach($event->changes as $change)
                                <p>
                                @if($change['product_id'])<a href="{{ route('products.stock-ledger', ['product' => $change['product_id'], 'variant_id' => $change['variant_id']]) }}">{{ $change['name'] }}</a>@else{{ $change['name'] }}@endif:
                                {{ $change['ordinary_delta'] > 0 ? 'بازگشت' : 'اختصاص' }} {{ $n(abs($change['ordinary_delta'])) }} قطعه
                                </p>
                            @endforeach
                            @if($event->omitted_changes)<p>{{ $n($event->omitted_changes) }} قطعهٔ دیگر در حسابرسی ثبت شده است؛ فهرست برای سبک‌ماندن صفحه کوتاه شده است.</p>@endif
                            <small>شناسهٔ رویداد: {{ $event->event_id }}</small>
                        </div></details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="psl-empty">هنوز رویداد مدیریتی حسابرسی‌شده‌ای برای این بسته ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if($bundleLedger['events']->hasPages())<footer>{{ $bundleLedger['events']->links() }}</footer>@endif
        <p class="psl-overview-note">این بخش سوابق حسابرسی ثبت‌شده را نشان می‌دهد؛ سهمیه‌ها و سفارش‌های قدیمی که پیش از ثبت این رویدادها ایجاد شده‌اند از این فهرست قابل بازسازی نیستند. اسناد فروش در بخش «فروش کالا» پایین صفحه باقی می‌مانند.</p>
    </section>
    @endif
    <section class="psl-warehouse-overview" aria-label="موجودی آزاد به تفکیک انبار">
        <h2>{{ $bundleLedger ? 'موجودی فیزیکی ثبت‌شده در انبارها' : 'موجودی آزاد فعلی' }}</h2>
        <div class="psl-balances">
        @foreach($overview['balances'] as $balance)
            <div class="psl-balance {{ $balance->quantity ? 'has-stock' : '' }}"><span>{{ $balance->name }}</span><strong>{{ $n($balance->quantity) }} <small>عدد</small></strong></div>
        @endforeach
        </div>
        @if($bundleLedger)<p>این عددها فقط موجودی فیزیکی بستهٔ آماده را نشان می‌دهند و سهمیهٔ قطعات سبد خودکار را شامل نمی‌شوند.</p>
        @else<p>رزرو فعلی مرکزی: {{ $n($summary->reserved) }} عدد · موجودی آزاد، پس از کسر رزرو است؛ دوباره از آن کم نکنید.</p>@endif
    </section>
    <section class="psl-overview" aria-label="خلاصه گردش کالا">
        <div class="psl-overview-heading"><div><h2>خلاصهٔ گردش کالا</h2><p>برای دیدن اسناد هر بخش، «مشاهده جزئیات» را بزنید.</p></div>
        <a href="{{ request()->fullUrlWithQuery(['category'=>'all','page'=>null]) }}">همهٔ جزئیات</a></div>
        <div class="psl-overview-grid">
        @foreach($overview['groups'] as $key=>$group)
            <article class="psl-overview-card" data-category="{{ $key }}">
                <h3>{{ $group['label'] }}</h3>
                @if(in_array($key,['purchases','sales','returns']))
                    <strong>{{ $n($group['quantity']) }} <small>عدد</small></strong>
                    <p>{{ ['purchases'=>'تعداد ثبت‌شده در خریدها','sales'=>'تعداد در فاکتورهای لغونشده','returns'=>'دریافت تأییدشده؛ شامل لغو و اصلاح فاکتور'][$key] }}</p>
                @else
                    <strong>{{ $n($group['records']) }} <small>سابقه</small></strong>
                    @if($key==='reservations')<p>مراحل ثبت و آزادسازی رزرو؛ جدا از خرید و فروش</p>
                    @else<p>{{ $key==='transfers'?'طبق حواله‌ها:':'گردش مستقل:' }} ورود {{ $n($group['in']) }} · خروج {{ $n($group['out']) }}</p>@endif
                @endif
                <a href="{{ request()->fullUrlWithQuery(['category'=>$key,'page'=>null]) }}">مشاهده جزئیات <span aria-hidden="true">←</span></a>
            </article>
        @endforeach
        </div>
        <p class="psl-overview-note">خرید و فروش، تعدادِ اسناد هستند؛ دریافت و انتقال، عملیات انبارند. این عددها را برای محاسبهٔ مانده با هم جمع نکنید؛ ممکن است یک عملیات، سند فروش و رسید مرتبط هم داشته باشد.</p>
    </section>
    <form class="psl-filter" method="get" action="{{ route('products.stock-ledger',$product) }}">
        @if($selectedVariant)<input type="hidden" name="variant_id" value="{{ $selectedVariant->id }}">@endif
        @if($filters['category'])<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
        <label class="psl-search">جست‌وجو<input class="form-control" name="q" value="{{ $filters['q'] }}" maxlength="150" placeholder="مدل، رنگ، کد کالا یا شماره سند…"></label>
        <label>نوع نمایش<select class="form-select" name="effect"><option value="">همهٔ سوابق</option><option value="recorded" @selected($filters['effect']==='recorded')>تغییر موجودی با قبل و بعد</option><option value="reference" @selected($filters['effect']==='reference')>اسناد و مراحل مرتبط</option><option value="incomplete" @selected($filters['effect']==='incomplete')>سایر سوابق ثبت‌شده</option></select></label>
        <label>انبار<select class="form-select" name="warehouse_id"><option value="">همهٔ انبارها</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)$filters['warehouse_id']===(string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></label>
        <div class="psl-filter-actions"><button class="btn btn-primary" type="submit">جست‌وجو</button><a class="psl-reset" href="{{ route('products.stock-ledger',array_filter(['product'=>$product,'variant_id'=>$selectedVariant?->id])) }}">پاک‌کردن</a></div>
    </form>
    @if($filters['category'] !== '' || $filters['q'] !== '' || $filters['effect'] !== '' || $filters['warehouse_id'] !== '')
    <section class="psl-book">
    <div class="psl-book-title"><div><strong>گردش کالا و مراحل سفارش</strong><span>{{ $n($movements->total()) }} سابقه مطابق جست‌وجو · ورود و خروج موجودی با علامت + و − مشخص است.</span></div><span>جدیدترین در بالا</span></div>
    <p class="psl-detail-scope">{{ $overview['groups'][$filters['category']]['label'] ?? 'همهٔ سوابق' }} · <a href="{{ request()->fullUrlWithQuery(['category'=>null,'page'=>null,'q'=>null,'effect'=>null,'warehouse_id'=>null]) }}">بستن جزئیات</a></p>
    <table><thead><tr><th>تاریخ</th><th>مدل / انبار</th><th>عملیات</th><th>تعداد / تغییر موجودی</th><th>سند و جزئیات</th></tr></thead><tbody>
    @forelse($movements as $movement)
        @php
            $source=$sources[$movement->id]; $recorded=$movement->evidence_level==='recorded'; $delta=$movement->available_delta; $status=$movement->event_status ?? null;
            $event=$movement->transaction_type==='stocktake_adjustment'?'انبارگردانی':($reasons[$movement->reason] ?? $movement->reason ?: 'ثبت موجودی');
            $tone=$recorded && $delta>0?'in':($recorded && $delta<0?'out':'reference');
            $reservation=($movement->history_kind ?? null)==='سابقه رزرو';
            $reservationStart=$reservation && str_starts_with((string)$movement->id,'reserve-');
            if($reservation) $event=$reservationStart?'ثبت رزرو سفارش':(str_contains($event,'مصرف رزرو')?'تبدیل رزرو به فاکتور':(str_contains($event,'آزادسازی')?'آزادسازی رزرو':'پایان رزرو سفارش'));
        @endphp
        <tr class="psl-row psl-row-{{ $tone }}" data-history-id="{{ $movement->id }}">
            <td class="psl-date" data-label="زمان"><span>{{ $fa(\App\Support\JalaliDate::date($movement->created_at)) }}</span><small>{{ $fa($movement->created_at?->format('H:i') ?? '') }}</small></td>
            <td class="psl-item" data-label="مدل / انبار"><strong>{{ $movement->variant?->variant_name ?: ($movement->variant_snapshot ?? ($movement->product_variant_id ? 'تنوع حذف‌شده #'.$fa($movement->product_variant_id) : 'سابقه بدون تنوع مشخص')) }}</strong><small>{{ $movement->warehouse?->name ?: 'انبار نامشخص' }}<span class="psl-code"> · {{ $fa($movement->variant?->variant_code ?? $movement->variant_code_snapshot ?? '') }}</span></small></td>
            <td class="psl-event" data-label="رویداد"><strong><i class="psl-event-dot is-{{ $tone }}" aria-hidden="true"></i>{{ $event }}</strong>@if($status)<small>{{ $statuses[$status] ?? 'وضعیت ثبت‌شده: '.$status }}</small>@endif</td>
            <td class="psl-change" data-label="تعداد / تغییر">
                @if($movement->inbound_context ?? null)<p class="psl-row-fact">دریافت: {{ $n($movement->inbound_context->accepted_quantity) }} از {{ $n($movement->inbound_context->expected_quantity) }} عدد</p>@endif
                @if($recorded)<strong class="psl-amount is-{{ $tone }}"><bdi dir="ltr">{{ $delta>0?'+':($delta<0?'−':'') }}{{ $n(abs($delta)) }}</bdi> <small>عدد</small></strong><span class="psl-effect-copy is-{{ $tone }}">{{ $delta>0?$n($delta).' عدد اضافه شد':($delta<0?$n(abs($delta)).' عدد کم شد':'موجودی تغییری نکرد') }}</span>
                @else<strong class="psl-document-quantity">{{ $n(isset($movement->expected_quantity)?$movement->accepted_quantity:$movement->quantity) }} <small>عدد</small></strong><span class="psl-document-caption">{{ isset($movement->expected_quantity)?'تعداد دریافت':($reservation?($reservationStart?'تعداد رزرو':'تعداد مربوط به رزرو'):($movement->evidence_level==='incomplete'?'تعداد ثبت‌شده':'تعداد در سند')) }}</span>@endif
                @if(isset($movement->expected_quantity))<p class="psl-row-fact">@if($status==='pending'){{ $n($movement->expected_quantity) }} عدد در انتظار دریافت؛ هنوز تحویل نشده.@elseif($status==='cancelled')درخواست دریافت لغو شده است.@elseif($movement->accepted_quantity===0)از {{ $n($movement->expected_quantity) }} عدد، هیچ کالایی تحویل نشد.@else{{ $n($movement->accepted_quantity) }} از {{ $n($movement->expected_quantity) }} عدد تحویل شد؛ اثر موجودی در ردیف گردش مربوط ثبت می‌شود.@endif</p>@endif
                @if($movement->snapshot_reliable)<div class="psl-snapshot"><span>موجودی قبل: {{ $n($movement->stock_before) }}</span><span aria-hidden="true">←</span><span>بعد: {{ $n($movement->stock_after) }}</span></div>
                @elseif(isset($movement->document_quantity_before))<small>تعداد سند: {{ $n($movement->document_quantity_before) }} ← {{ $n($movement->document_quantity_after) }}</small>@endif
            </td>
            <td class="psl-source" data-label="سند مرتبط">@if($source['url'])<a class="psl-source-link" href="{{ $source['url'] }}">{{ $fa($source['label']) }} <span aria-hidden="true">↗</span></a>@else<span class="psl-source-label">{{ $fa($source['label']) }}</span>@endif
                <details class="psl-row-details"><summary>جزئیات</summary><div>
                    @if(!$recorded)
                        @if($movement->evidence_level==='incomplete' && !$reservation)
                            <p>تعداد این ردیف از سابقهٔ عملیات گرفته شده؛ برای محاسبهٔ تغییر قطعی، مقدار قبل و بعد قابل تطبیق در دسترس نیست. به همین دلیل با علامت ورود یا خروج نمایش داده نمی‌شود.</p>
                        @else
                            <p>این تعداد، تغییر مستقل موجودی نیست؛ مقدار مربوط به سند یا مرحلهٔ عملیات است و نباید دوباره با ورود و خروج جمع شود.</p>
                        @endif
                    @endif
                    <p class="psl-evidence-note">{{ $movement->evidence_note }}</p><p>{{ $movement->effect_label }}</p>@if($movement->related_url ?? null)<p><a href="{{ $movement->related_url }}">{{ $fa($movement->related_reference) }} ↗</a></p>@endif<p>ثبت‌کننده: {{ $movement->user?->name ?: 'نامشخص' }}</p><p>{{ $movement->note ?: 'توضیح بیشتری ثبت نشده است.' }}</p><small>شناسهٔ سابقه: {{ $fa($movement->id) }}</small></div></details>
            </td>
        </tr>
    @empty<tr><td colspan="5" class="psl-empty"><strong>سابقه‌ای پیدا نشد</strong><span>جست‌وجو یا فیلترها را تغییر دهید.</span></td></tr>@endforelse
    </tbody></table><footer><span>{{ $n($movements->firstItem() ?: 0) }} تا {{ $n($movements->lastItem() ?: 0) }} از {{ $n($movements->total()) }} سابقه</span>{{ $movements->links() }}</footer></section>
    @endif
</div>
@endsection
