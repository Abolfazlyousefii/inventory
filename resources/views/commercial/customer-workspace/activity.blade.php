<div class="cf-layout">
<section class="panel cf-compose"><div class="panel-heading"><strong>ثبت ارتباط با مشتری</strong><span class="cf-dot">●</span></div>
@if($customer->is_active)
<form class="cf-form" data-cf-form action="{{ route('commercial.customers.interactions.store',$customer) }}" method="post">@csrf
<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>نوع ارتباط<select name="kind">@foreach(\App\Models\CustomerInteraction::kinds() as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>گزارش ارتباط<textarea name="body" rows="4" required maxlength="3000" placeholder="چه گفت‌وگویی انجام شد و نتیجه چه بود؟"></textarea></label>
<details class="cf-next"><summary>＋ پیگیری بعدی <small>اختیاری</small></summary><div class="cf-next-body">
<label>موضوع پیگیری<input name="followup_title" maxlength="200" placeholder="مثلاً تماس برای تکمیل سفارش"></label>
<div class="cf-two"><label>تاریخ شمسی<input name="due_date" maxlength="10" data-jdp data-jdp-only-date autocomplete="off" placeholder="۱۴۰۵/۰۹/۰۵" dir="ltr"></label><label>ساعت<input type="time" name="due_time" value="09:00" dir="ltr"></label></div>
<small>مسئول پیگیری: {{ $customer->assignment?->seller?->name ?: 'ابتدا مسئول فعال برای مشتری تعیین کنید' }}</small>
</div></details>
<p class="cf-message" data-cf-message role="status" aria-live="polite"></p><button class="cf-primary" type="submit">ثبت ارتباط</button>
<small class="cf-hint">نام ثبت‌کننده و زمان خودکار ذخیره می‌شوند؛ سابقهٔ ثبت‌شده قابل حذف یا بازنویسی نیست.</small></form>
@else<p class="ce-help">مشتری غیرفعال است؛ ثبت ارتباط جدید مجاز نیست.</p>@endif
</section>
<div><section class="panel cf-open-panel"><div class="panel-heading"><strong>پیگیری‌های باز این مشتری</strong><a class="panel-link" href="{{ route('commercial.followups.index',['scope'=>'all']) }}">پیگیری‌های من ←</a></div>
@forelse($openFollowups as $task) @include('commercial.customer-workspace.followup-card',['compact'=>true]) @empty <div class="cf-empty">پیگیری بازی وجود ندارد.</div> @endforelse
@if($openFollowups->count()===5)<p class="ce-help">نزدیک‌ترین پنج پیگیری نمایش داده می‌شوند؛ همهٔ موارد در سوابق پایین موجودند.</p>@endif</section>
<section class="panel cf-timeline"><div class="panel-heading"><strong>سوابق ارتباطات</strong><span>{{ number_format($records->total()) }} ارتباط</span></div>
@forelse($records as $note)<article class="cf-note"><div class="cf-note-head"><span class="cf-kind">{{ \App\Models\CustomerInteraction::kinds()[$note->kind]??$note->kind }}</span><strong>{{ $note->author?->name??'کاربر قبلی' }}</strong><time>{{ \App\Support\JalaliDate::dateTime($note->created_at) }}</time></div><p>{{ $note->body }}</p>
@if($note->followup) @include('commercial.customer-workspace.followup-card',['task'=>$note->followup,'compact'=>true,'history'=>true]) @endif</article>
@empty<div class="cf-empty">هنوز ارتباطی ثبت نشده است.<small>اولین تماس یا یادداشت را از فرم کنار صفحه ثبت کنید.</small></div>@endforelse
<nav class="ce-pagination" aria-label="صفحه‌بندی ارتباطات">@if($records->previousPageUrl())<a data-ce-tab href="{{ $records->previousPageUrl() }}">قبلی</a>@endif<span>صفحه {{ $records->currentPage() }} از {{ $records->lastPage() }}</span>@if($records->nextPageUrl())<a data-ce-tab href="{{ $records->nextPageUrl() }}">بعدی</a>@endif</nav>
</section></div></div>
