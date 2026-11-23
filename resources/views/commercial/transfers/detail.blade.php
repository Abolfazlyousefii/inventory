@php($labels=['draft'=>'پیش‌نویس','registered'=>'انجام‌شده','cancelled'=>'لغوشده'])
@php($actions=['created'=>'ایجاد سند','draft_updated'=>'ویرایش پیش‌نویس','registered'=>'ثبت و اجرای انتقال','cancelled'=>'لغو انتقال','draft_deleted'=>'حذف پیش‌نویس'])
<div class="dialog-body">
<div class="detail-banner"><div><small>شمارهٔ سند</small><h2>{{ number_format($document->number,0,'','') }}</h2></div><span class="pill {{ ['draft'=>'draft','registered'=>'done','cancelled'=>'reversed'][$document->status] }}">{{ $labels[$document->status] }}</span></div>
<div class="two-fields detail-grid"><div><small>نوع انتقال</small><b>{{ $document->kind==='customer'?'انتقال مشتری':'انتقال فاکتور' }}</b></div><div><small>تاریخ ثبت سند</small><b>{{ \App\Services\Personnel\PersonnelDates::display($document->created_at) }}</b></div><div><small>از همکار</small><b>{{ $document->from_name }}</b></div><div><small>به همکار</small><b>{{ $document->to_name }}</b></div></div>
<div class="note">{{ $document->kind==='customer'?'فقط مسئول مشتری تغییر می‌کند؛ فاکتورهای قبلی و حساب مشتری منتقل نمی‌شوند.':'فروشندهٔ فاکتور و پیش‌فاکتور متصل تغییر می‌کند؛ مسئول پروندهٔ مشتری ثابت می‌ماند. آثار پورسانت طبق روال مالی فعلی محاسبه می‌شوند.' }}</div>
<h3>موارد سند ({{ $document->items->count() }})</h3><ul class="detail-items">@foreach($document->items as $item)<li>{{ $item->label }}</li>@endforeach</ul>
<h3>دلیل انتقال</h3><p class="reason-text">{{ $document->reason }}</p>
<h3>تاریخچه</h3><ol class="timeline">@foreach($document->events as $event)<li><b>{{ $actions[$event->action]??$event->action }}</b> · {{ $event->actor_name }}<small>{{ \App\Services\Personnel\PersonnelDates::display($event->created_at) }}</small>@if($event->reason)<p>{{ $event->reason }}</p>@endif</li>@endforeach</ol>
<div class="error" role="alert" data-detail-error></div>
</div>
@unless($printing??false)
<footer class="dialog-footer inline-actions"><a class="secondary" target="_blank" rel="noopener" href="{{ route('commercial.transfers.show',[$document,'print'=>1]) }}">چاپ سند</a>
@if($document->status==='draft')
<button class="secondary" type="button" data-edit="{{ route('commercial.transfers.show',$document) }}">ویرایش پیش‌نویس</button>
<form data-document-action action="{{ route('commercial.transfers.destroy',$document) }}" data-method="DELETE">@csrf<input type="hidden" name="version" value="{{ $document->version }}"><button class="danger" type="submit">حذف پیش‌نویس</button></form>
<form data-document-action action="{{ route('commercial.transfers.register',$document) }}">@csrf<input type="hidden" name="version" value="{{ $document->version }}"><button class="primary" type="submit">ثبت انتقال</button></form>
@endif</footer>
@if($document->status==='registered'&&!$document->legacy_key)
<form class="detail-form" data-document-action data-confirm action="{{ route('commercial.transfers.cancel',$document) }}">@csrf<input type="hidden" name="version" value="{{ $document->version }}"><label class="field">دلیل لغو<textarea name="reason" required maxlength="1000" rows="2" placeholder="علت لغو انتقال…"></textarea></label><small>لغو، انتقال را برمی‌گرداند و سابقه را حفظ می‌کند. در صورت تغییر اطلاعات بعدی، لغو خودکار مسدود می‌شود.</small><button class="danger" type="submit">لغو انتقال</button></form>
@if($document->kind==='invoice')<p class="detail-form"><small>لغو انتقال، ردیف تاریخی پورسانت قبلی را دوباره فعال نمی‌کند؛ فاکتور برای بررسی مجدد پورسانت آزاد می‌ماند.</small></p>@endif
@endif
@endunless
