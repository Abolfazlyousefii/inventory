@php
$issued=(int)$monthly->where('kind','issued')->sum('amount');
$promised=(int)$monthly->where('kind','promise')->sum('amount');
$funding=(int)$monthly->where('kind','deposit')->reject(fn($e)=>$e->isBounced())->sum('amount');
$shortfall=max(0,$issued+$promised-$funding);
$active=fn($rows,$kind)=>$rows->where('kind',$kind)->reject(fn($e)=>$e->isBounced());
$compact=fn($v)=>$v>=1000000?$fa(number_format($v/1000000,($v%1000000===0?0:2))).' م':$fa(number_format($v));
@endphp
<div class="scq-layout" id="chequeCalendar">
 <aside class="scq-summary scq-panel">
  <div class="scq-head"><h5>خلاصه ماه</h5><span class="scq-note">{{ $fa($start->format('F Y')) }}</span></div>
  <div class="scq-stat scq-stat-issued"><span>چک‌های صادره پرداخت‌نشده</span><strong>{{ $money($issued) }}</strong></div>
  <div class="scq-stat scq-stat-promise"><span>تعهدات حقوق، بیمه و سایر</span><strong>{{ $money($promised) }}</strong></div>
  <div class="scq-stat scq-stat-deposit"><span>چک‌های مشتری در حساب</span><strong>{{ $money($funding) }}</strong><small>براساس سررسید؛ تأمین برنامه‌ریزی‌شده</small></div>
  <div class="scq-shortfall"><span>{{ $funding>$issued+$promised?'مازاد برنامه‌ریزی‌شده ماه':'کسری برنامه‌ریزی‌شده ماه' }}</span><strong>{{ $money(abs($issued+$promised-$funding)) }}</strong><small>بدون احتساب موجودی نقد بانک</small></div>
  <details class="scq-summary-help"><summary>درباره محاسبات</summary><p class="scq-note">ثبت در این صفحه سند هزینه یا پرداخت جدید ایجاد نمی‌کند. چک برگشتی صادره تا تعیین تکلیف، همچنان تعهد محسوب می‌شود.</p></details>
 </aside>
 <section class="scq-panel scq-calendar-panel">
  <div class="scq-nav"><a class="btn btn-outline-primary btn-sm" href="?month={{ $previous }}">ماه قبل</a><h5>{{ $fa($start->format('F Y')) }}</h5><a class="btn btn-outline-primary btn-sm" href="?month={{ $next }}">ماه بعد</a></div>
  <div class="scq-note mt-2">مبالغ به ریال • «م» = میلیون ریال</div>
  <div class="scq-grid">
   @foreach(['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'] as $weekday)<div class="scq-week">{{ $weekday }}</div>@endforeach
   @for($blank=0;$blank<$offset;$blank++)<div class="scq-day" aria-hidden="true"></div>@endfor
   @for($day=1;$day<=$days;$day++)
    @php
     $j=\Morilog\Jalali\Jalalian::fromFormat('Y/m/d',$month.'/'.str_pad($day,2,'0',STR_PAD_LEFT));
     $date=$j->toCarbon()->toDateString();$rows=$monthly->filter(fn($e)=>$e->calendarDate()===$date);
     $balance=$coverage->balance($entries,$date);$bounced=$rows->filter(fn($e)=>$e->isBounced());
    @endphp
    <button type="button" class="scq-day" data-day="{{ $date }}" data-date-label="{{ $fa($j->format('Y/m/d')) }}" data-shortfall="{{ $money($balance['shortfall']) }}" aria-label="جزئیات {{ $fa($j->format('Y/m/d')) }}">
     <span class="scq-day-number">{{ $fa($day) }}</span>
     @if($active($rows,'issued')->count())<span class="scq-pill scq-out">صادره <b>{{ $compact($active($rows,'issued')->sum('amount')) }}</b></span>@endif
     @if($active($rows,'deposit')->count())<span class="scq-pill scq-in">خوابانده <b>{{ $compact($active($rows,'deposit')->sum('amount')) }}</b></span>@endif
     @foreach($rows->where('kind','promise')->groupBy('category') as $group)<span class="scq-pill scq-promise">{{ $group->first()->categoryLabel() }} <b>{{ $compact($group->sum('amount')) }}</b></span>@endforeach
     @if($bounced->count())<span class="scq-pill scq-bounced">برگشتی <b>{{ $compact($bounced->sum('amount')) }}</b></span>@endif
     @if($rows->whereIn('kind',['issued','promise'])->count())<small class="scq-coverage {{ $balance['shortfall']>0?'scq-unfunded':'scq-funded' }}">{{ $balance['shortfall']>0?'کسری تجمعی '.$compact($balance['shortfall']):'✓ تأمین برنامه‌ریزی‌شده' }}</small>@endif
    </button>
   @endfor
  </div>
  <p class="scq-note mt-3 mb-1">روی هر روز کلیک کنید تا گیرنده، شماره چک، مبلغ و جزئیات را ببینید. چک مشتری در سررسید نمایش داده می‌شود؛ تاریخ واقعی خواباندن جداگانه حفظ می‌شود.</p>
  <button type="button" class="btn btn-sm btn-outline-secondary" id="showMonth">جزئیات همه ماه</button>
 </section>
</div>
<dialog id="dayDetails" aria-labelledby="dayTitle">
 <header class="scq-modal-head"><h5 id="dayTitle">جزئیات این ماه</h5><button type="button" class="btn btn-outline-secondary btn-sm" data-close="dayDetails">بستن</button></header>
 <div class="scq-modal-body">
  <p class="scq-note" id="dayCoverage">مانده برنامه‌ریزی‌شده ابتدای ماه: {{ $money($opening['balance']) }}؛ شامل موجودی واقعی بانک نیست.</p>
  @forelse($monthly as $e)
   <article class="scq-entry" data-entry-day="{{ $e->calendarDate() }}">
    <div class="scq-head"><strong>{{ $e->party }}</strong><strong>{{ $money($e->amount) }}</strong></div>
    <div class="scq-entry-meta"><span class="scq-pill {{ $e->isBounced()?'scq-bounced':($e->kind==='issued'?'scq-out':($e->kind==='promise'?'scq-promise':'scq-in')) }}">{{ $e->isBounced()?'برگشتی • ':'' }}{{ $e->kind==='issued'?'چک صادره':($e->kind==='promise'?'تعهد • '.$e->categoryLabel():'چک مشتری خوابانده‌شده') }}</span>
    <span>سررسید / موعد: {{ $fa(\Morilog\Jalali\Jalalian::fromDateTime($e->calendarDate())->format('Y/m/d')) }}</span>
    @if($e->cheque_number)<span>شماره: {{ $fa($e->cheque_number) }}</span>@endif
    @if($e->kind==='deposit')<span>خواباندن: {{ $fa(\Morilog\Jalali\Jalalian::fromDateTime($e->effective_date)->format('Y/m/d')) }}</span>@endif</div>
    @if($e->kind==='deposit' && !$e->cheque?->due_date)<p class="text-warning small">سررسید این سابقه موجود نیست؛ موقتاً تاریخ خواباندن نمایش داده شده است.</p>@endif
    @if($e->notes)<p class="scq-note">{{ $e->notes }}</p>@endif
    @if($e->status_reason)<p class="scq-note">توضیح وضعیت: {{ $e->status_reason }}</p>@endif
    @if($e->kind==='deposit' && in_array($e->cheque?->status,['bounced','returned'],true))
     <p class="scq-note">چک اصلی برگشتی است؛ وضعیت آن باید در بخش چک‌ها بررسی شود.</p>
    @elseif($e->kind!=='promise')
     <details class="mt-2"><summary class="scq-note">{{ $e->status==='bounced'?'اصلاح وضعیت برگشتی':'ثبت برگشتی در گزارش' }}</summary>
      <form class="scq-status-form" method="post" action="{{ route('finance.saeidian-cheques.status',$e) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $e->status==='bounced'?'active':'bounced' }}"><input type="hidden" name="expected_status" value="{{ $e->status }}"><label>دلیل تغییر وضعیت<input name="reason" required minlength="3" maxlength="2000" placeholder="توضیح الزامی"></label><button class="btn btn-sm btn-outline-secondary">{{ $e->status==='bounced'?'بازگشت به فعال':'ثبت برگشتی' }}</button><p class="scq-note">فقط وضعیت این گزارش تغییر می‌کند؛ چک اصلی و حساب مشتری تغییر نمی‌کنند.</p></form>
     </details>
    @endif
   </article>
  @empty<p class="scq-note">برای این ماه موردی ثبت نشده است.</p>@endforelse
  <p class="scq-note" id="emptyDay" hidden>در این روز موردی ثبت نشده است.</p>
 </div>
</dialog>
