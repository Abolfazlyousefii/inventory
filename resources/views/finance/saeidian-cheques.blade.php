@extends('layouts.app')
@section('content')
@php
$fa=fn($v)=>strtr((string)$v,array_combine(str_split('0123456789'),preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY)));
$money=fn($v)=>$fa(number_format($v)).' ریال';
$chequeDate=function($value)use($fa){ if(!$value)return '—'; try { if($value->year<1700)return $fa($value->format('Y/m/d')); return $fa(\Morilog\Jalali\Jalalian::fromDateTime($value)->format('Y/m/d')); }catch(\Throwable $e){return 'تاریخ نیازمند بررسی';} };
$previous=$start->subMonths(1)->format('Y/m');$next=$end->format('Y/m');
$days=$start->getMonthDays();$offset=($start->toCarbon()->dayOfWeek+1)%7;
@endphp
<style>
.scq{max-width:1400px;margin:auto;color:var(--bs-body-color,#17384d)}.scq-head,.scq-nav{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.scq-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:20px 0}.scq-card,.scq-panel{background:var(--bs-body-bg,#fff);border:1px solid #d6e1e8;border-radius:14px;padding:18px}.scq-card strong{display:block;font-size:23px;margin-top:12px}.scq-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));margin-top:16px;border-top:1px solid #d6e1e8;border-right:1px solid #d6e1e8}.scq-day,.scq-week{border-left:1px solid #d6e1e8;border-bottom:1px solid #d6e1e8;padding:8px;min-width:0}.scq-day{height:68px;min-height:68px;vertical-align:top;cursor:pointer;background:transparent;color:inherit;text-align:right}.scq-day:hover,.scq-day.selected{background:#e9f3fb;outline:2px solid #2684c8;outline-offset:-2px}.scq-week{text-align:center;background:#eef2f6;color:#17384d}.scq-pill{display:block;font-size:11px;padding:1px 4px;border-radius:4px;margin:2px 0;line-height:1.4}.scq-in{background:#e2f3ea;color:#13633f}.scq-out{background:#fce8ec;color:#9e2743}.scq-layout{display:grid;grid-template-columns:1fr;gap:18px}.scq-detail{margin-top:12px}.scq-head{margin-bottom:12px}.scq-actions{display:flex;gap:8px;flex-wrap:wrap}.scq-choice{border:1px solid #d6e1e8;border-radius:8px;padding:9px;cursor:pointer}.scq-choice strong{float:left;font-size:13px}.scq-choice:has(input:checked){border-color:#26845d;background:#e2f3ea;color:#17384d}.scq dialog{max-height:90vh;overflow:auto}.scq [hidden]{display:none!important}.scq-detail td,.scq-detail th{padding:12px;border-bottom:1px solid #d6e1e8}.scq dialog{width:min(620px,95vw);border:1px solid #d6e1e8;border-radius:15px;padding:24px;background:var(--bs-body-bg,#fff);color:inherit}.scq dialog::backdrop{background:#10233488}.scq label{display:block;margin:12px 0}.scq input,.scq textarea{width:100%;padding:10px;border:1px solid #bacbd6;border-radius:7px;background:var(--bs-body-bg,#fff);color:inherit}.scq .scq-radio{width:auto}.scq-note{color:#607586;font-size:13px;line-height:1.9}@media(max-width:900px){.scq-layout{grid-template-columns:1fr}.scq-cards{grid-template-columns:1fr}.scq-day{height:64px;min-height:64px;padding:4px}.scq-pill{font-size:10px}}

/* Compact calendar and one scrolling surface per dialog. */
.scq-layout {max-width:960px;margin:18px auto 0}
.scq-layout > .scq-panel {padding:18px 22px}
.scq-grid {gap:6px;border:0;margin-top:14px}
.scq-week {border:0;border-radius:18px;background:#20546a;color:#fff;padding:7px 2px;font-size:12px}
.scq-day {height:62px;min-height:62px;border:1px solid #dce5e9;border-radius:8px;padding:6px 8px;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;font-weight:600;line-height:1.3;background:var(--bs-body-bg,#fff)}
.scq-day[aria-hidden="true"] {border:0;background:transparent;pointer-events:none}
.scq-day.selected {outline:2px solid #29738e;background:#e7f2f6;color:#17384d}
.scq-day:hover:not([aria-hidden]) {border-color:#29738e}
.scq-pill {font-size:10px;line-height:1.3;margin:1px 0;font-weight:400}
.scq .scq-note {line-height:1.7}
.scq dialog {padding:0;max-height:88dvh;overflow:hidden;width:min(640px,calc(100vw - 28px));border-radius:14px;box-shadow:0 20px 70px #10233426}
.scq-modal-head {padding:16px 20px;border-bottom:1px solid #dce5e9;display:flex;justify-content:space-between;align-items:center;gap:12px}
.scq-modal-head h5 {margin:0;font-size:17px;font-weight:700}
.scq-modal-body {padding:16px 20px;overflow:auto;max-height:calc(88dvh - 150px);overscroll-behavior:contain;scrollbar-gutter:stable}
.scq-modal-foot {padding:12px 20px;border-top:1px solid #dce5e9;display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--bs-body-bg,#fff)}
.scq-modal-foot .scq-note {margin:0}
.scq-search {display:flex;gap:8px;align-items:end;margin-bottom:12px;position:sticky;top:-16px;background:var(--bs-body-bg,#fff);padding:8px 0;z-index:1}
.scq-search label {flex:1;margin:0}
.scq-search input {height:42px}
.scq-choice {margin:8px 0!important;padding:11px 12px;display:grid!important;grid-template-columns:20px 1fr;gap:4px 10px;align-items:center}
.scq-choice input {grid-row:1/3;margin:0}
.scq-choice-top {display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px}
.scq-choice strong {float:none;white-space:nowrap}
.scq-choice small {grid-column:2;color:#607586;font-size:11px}
.scq-selected {border:1px solid #a3cab7;border-radius:9px;background:#eef8f2;padding:13px;color:#17384d;margin-bottom:14px}
.scq-modal-body textarea {min-height:64px;max-height:110px}
.scq-step-note {font-size:12px;color:#607586;margin:0 0 6px}
html:has(dialog[open]) {overflow:hidden}
@media(max-width:600px){.scq-layout > .scq-panel{padding:12px}.scq-grid{gap:4px}.scq-day{height:62px;min-height:62px;padding:5px 4px}.scq-week{font-size:10px}.scq-modal-head,.scq-modal-body,.scq-modal-foot{padding:12px}.scq-modal-foot .scq-note{font-size:11px}.scq-choice-top{gap:4px}.scq-pill{font-size:9px}}


.scq {--scq-ink:#17384d;--scq-accent:#20546a}
.scq-form-grid {display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
.scq-form-grid label {margin:0;font-size:13px;font-weight:600}
.scq-full {grid-column:1/-1}
.scq-form-grid input,.scq-form-grid textarea {margin-top:6px;font-weight:400}
.scq input:focus-visible,.scq textarea:focus-visible,.scq button:focus-visible,.scq a:focus-visible{outline:2px solid #29738e;outline-offset:3px}
.scq-feedback {min-height:0;font-size:12px;color:#607586}
.scq-feedback:not(:empty){padding:6px 0}
.scq-layout[aria-busy="true"] {opacity:.5;pointer-events:none;transition:opacity 120ms}
.scq h3{font-size:21px;font-weight:700}
.scq-nav h5{font-size:18px;font-weight:700;margin:0}
.scq-modal-head .scq-step-note{margin-bottom:5px}
.scq-modal-body .scq-note {margin-top:14px}
.scq-choice:hover{border-color:#70a394}
@keyframes scq-reveal{from{opacity:.2;transform:translateY(4px)}to{opacity:1;transform:none}}
.scq-layout,.scq dialog[open]{animation:scq-reveal 180ms ease-out}
@media(prefers-reduced-motion:reduce){.scq-layout,.scq dialog[open]{animation:none}.scq-layout[aria-busy="true"]{transition:none}}
@media(max-width:480px){.scq-form-grid{grid-template-columns:1fr}.scq-full{grid-column:auto}}


.scq [data-cheque-date]{cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='none' stroke='%2320546a' stroke-width='1.6'%3E%3Crect x='2' y='4' width='14' height='12' rx='2'/%3E%3Cpath d='M2 8h14M6 2v4M12 2v4'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:40px}
.scq #chequeDateDialog{width:min(360px,94vw);padding-bottom:16px;overflow:visible}
.scq #chequeDatePicker{width:calc(100% - 32px);margin:12px 16px;border-color:#dce5e9;text-align:center}
.scq #chequeDateDialog jdp-container{position:relative!important;top:auto!important;left:auto!important;right:auto!important;margin:0 auto!important;box-shadow:none!important;width:calc(100% - 24px)!important;max-width:100%}
.scq #chequeDateDialog jdp-overlay{display:none!important}
.scq-day:focus-visible{outline:2px solid #29738e;outline-offset:2px}

</style>
<div class="scq" dir="rtl">
<div class="scq-head"><div><h3>تقویم چک‌های سعیدیان</h3><p class="scq-note">چک‌های صادره و تأمین وجه از چک‌های مشتری • مبالغ به ریال</p></div><div class="scq-actions"><button class="btn btn-primary" data-open="issuedDialog">＋ ثبت چک صادره</button><button class="btn btn-outline-success" data-open="depositDialog">خواباندن چک مشتری</button></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<div id="calendarFeedback" class="scq-feedback" role="status" aria-live="polite"></div>
<div class="scq-layout" id="chequeCalendar"><section class="scq-panel"><div class="scq-nav"><a class="btn btn-outline-primary" href="?month={{ $previous }}">ماه قبل</a><h5>{{ $fa($start->format('F Y')) }}</h5><a class="btn btn-outline-primary" href="?month={{ $next }}">ماه بعد</a></div>
<div class="scq-grid">@foreach(['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'] as $weekday)<div class="scq-week">{{ $weekday }}</div>@endforeach
@for($blank=0;$blank<$offset;$blank++)<div class="scq-day" aria-hidden="true"></div>@endfor
@for($day=1;$day<=$days;$day++)
@php $j=\Morilog\Jalali\Jalalian::fromFormat('Y/m/d',$month.'/'.str_pad($day,2,'0',STR_PAD_LEFT));$date=$j->toCarbon()->toDateString();$rows=$monthly->filter(fn($e)=>$e->effective_date->toDateString()===$date);$balance=$coverage->balance($entries,$date); @endphp
<button type="button" class="scq-day" data-day="{{ $date }}" data-date-label="{{ $fa($j->format('Y/m/d')) }}" data-shortfall="{{ $money($balance['shortfall']) }}" aria-label="جزئیات {{ $fa($j->format('Y/m/d')) }}">{{ $fa($day) }}
@if($rows->where('kind','issued')->count())<span class="scq-pill scq-out">{{ $fa($rows->where('kind','issued')->count()) }} چک صادره</span>@endif
@if($rows->where('kind','deposit')->count())<span class="scq-pill scq-in">{{ $fa($rows->where('kind','deposit')->count()) }} خوابانده‌شده</span>@endif
</button>
@endfor</div><p class="scq-note mt-3">صادره بر اساس سررسید؛ تأمین وجه بر اساس تاریخ خواباندن. هر روز را برای مشاهده جزئیات انتخاب کنید.</p></section>
</div>
<section class="scq-panel scq-detail" id="dayDetails" hidden><div class="scq-head"><h5 id="dayTitle">جزئیات این ماه</h5><button type="button" class="btn btn-outline-secondary" id="showMonth">نمایش همه ماه</button></div><p class="scq-note" id="dayCoverage">مانده ابتدای ماه: {{ $money($opening['balance']) }}؛ محاسبات تجمعی از شروع ثبت در این بخش است و شامل مانده واقعی بانک نیست.</p><div class="table-responsive"><table style="width:100%"><thead><tr><th>تاریخ</th><th>نوع</th><th>طرف حساب</th><th>شماره چک</th><th>مبلغ</th><th>توضیح</th></tr></thead><tbody>
@forelse($monthly as $e)<tr data-entry-day="{{ $e->effective_date->toDateString() }}"><td>{{ $fa(\Morilog\Jalali\Jalalian::fromDateTime($e->effective_date)->format('Y/m/d')) }}</td><td><span class="scq-pill {{ $e->kind==='issued'?'scq-out':'scq-in' }}">{{ $e->kind==='issued'?'صادره سعیدیان':'خوابانده در حساب' }}</span></td><td>{{ $e->party }}</td><td>{{ $fa($e->cheque_number ?: '—') }}</td><td>{{ $money($e->amount) }}</td><td>{{ $e->notes ?: '—' }}</td></tr>@empty<tr><td colspan="6">در این ماه چکی ثبت نشده است.</td></tr>@endforelse
</tbody></table></div></section>
<dialog id="depositDialog" aria-labelledby="depositTitle">
    <header class="scq-modal-head"><div><p class="scq-step-note" id="depositStep">۱ از ۲ · انتخاب چک</p><h5 id="depositTitle">خواباندن چک مشتری</h5></div><button type="button" class="btn btn-outline-secondary" data-close="depositDialog">بستن</button></header>
    <section id="depositSelect">
        <div class="scq-modal-body">
            <form method="get" class="scq-search"><input type="hidden" name="dialog" value="deposit"><input type="hidden" name="month" value="{{ $month }}"><label>نام مشتری، شماره چک یا مبلغ<input name="q" value="{{ $search }}" placeholder="جستجوی چک…"></label><button class="btn btn-outline-primary">جستجو</button></form>
            @forelse($cheques as $c)
                <label class="scq-choice"><input class="scq-radio" type="radio" name="selected_cheque" value="{{ $c->id }}" data-caption="{{ $c->customer_name ?: 'مشتری' }} · {{ $money($c->amount) }} · شماره {{ $fa($c->cheque_number ?: '—') }}"><span class="scq-choice-top"><span>{{ $c->customer_name ?: 'مشتری' }}</span><strong>{{ $money($c->amount) }}</strong></span><small>شماره {{ $fa($c->cheque_number ?: '—') }} · سررسید {{ $chequeDate($c->due_date) }}</small></label>
            @empty<p class="scq-note">چکی یافت نشد؛ نام یا شماره دیگری جستجو کنید.</p>@endforelse
            @if($cheques->count()===40)<p class="scq-note">۴۰ نتیجه؛ برای یافتن سریع‌تر، جستجو کنید.</p>@endif
        </div>
        <footer class="scq-modal-foot"><span class="scq-note">یک چک انتخاب کنید.</span><button type="button" class="btn btn-primary" id="depositNext" disabled>ادامه</button></footer>
    </section>
    <form id="depositConfirm" hidden method="post" action="{{ route('finance.saeidian-cheques.store') }}">@csrf<input type="hidden" name="kind" value="deposit"><input type="hidden" name="cheque_id" id="depositChequeId" value="{{ old('cheque_id') }}">
        <div class="scq-modal-body"><div class="scq-selected" id="selectedChequeCaption">چک انتخاب‌شده</div><label>تاریخ خواباندن (شمسی)<input name="effective_date" data-cheque-date readonly value="{{ old('kind')==='deposit' ? old('effective_date') : \Morilog\Jalali\Jalalian::now()->format('Y/m/d') }}" required dir="ltr"></label><label>توضیحات (اختیاری)<textarea name="notes" maxlength="2000" rows="2">{{ old('kind')==='deposit' ? old('notes') : '' }}</textarea></label><label><input type="checkbox" name="deposited_confirmation" value="1" class="scq-radio" required> در حساب سعیدیان خوابانده شده است</label></div>
        <footer class="scq-modal-foot"><button type="button" class="btn btn-outline-secondary" id="depositBack">تغییر چک</button><button class="btn btn-success">ثبت خواباندن</button></footer>
    </form>
</dialog>
<dialog id="issuedDialog" aria-labelledby="issuedTitle">
<header class="scq-modal-head"><div><p class="scq-step-note">چک پرداختی سعیدیان</p><h5 id="issuedTitle">ثبت چک صادره</h5></div><button type="button" class="btn btn-outline-secondary" id="closeIssued">بستن</button></header>
<form method="post" action="{{ route('finance.saeidian-cheques.store') }}">@csrf<input type="hidden" name="kind" value="issued">
<div class="scq-modal-body"><div class="scq-form-grid">
<label class="scq-full">گیرنده<input name="party" required maxlength="255" autocomplete="off" placeholder="نام شخص یا شرکت" value="{{ old('party') }}"></label>
<label>شماره چک<input name="cheque_number" required maxlength="255" dir="ltr" value="{{ old('cheque_number') }}"></label>
<label>مبلغ (ریال)<input name="amount" type="text" inputmode="numeric" data-cheque-money maxlength="19" autocomplete="off" required dir="ltr" value="{{ old('amount') }}"></label>
<label class="scq-full">سررسید (شمسی)<input name="effective_date" data-cheque-date readonly aria-label="انتخاب سررسید شمسی" required dir="ltr" placeholder="انتخاب از تقویم" value="{{ old('effective_date') }}"></label>
<label class="scq-full">توضیحات (اختیاری)<textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
</div><p class="scq-note mb-0">ثبت در برنامهٔ تأمین وجه؛ بدون تغییر گردش حساب اشخاص.</p></div>
<footer class="scq-modal-foot"><button type="button" class="btn btn-outline-secondary" data-close="issuedDialog">انصراف</button><button class="btn btn-primary">ثبت چک صادره</button></footer>
</form></dialog>
<dialog id="chequeDateDialog" aria-labelledby="chequeDateTitle"><header class="scq-modal-head"><h5 id="chequeDateTitle">انتخاب تاریخ شمسی</h5><button type="button" class="btn btn-outline-secondary" data-close="chequeDateDialog">بستن تقویم</button></header><input id="chequeDatePicker" data-jdp-only-date readonly aria-label="تاریخ انتخاب‌شده" dir="ltr"></dialog>
</div>
<script>
document.querySelectorAll('[data-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.open).showModal()));document.querySelectorAll('[data-close]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.close).close()));document.getElementById('closeIssued').addEventListener('click',()=>document.getElementById('issuedDialog').close());

const normalizeChequeDigits=value=>String(value).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));
document.querySelectorAll('[data-cheque-money]').forEach(input=>{
 const format=()=>{const before=input.value;const caret=input.selectionStart??before.length;const digitsBefore=normalizeChequeDigits(before.slice(0,caret)).replace(/[^0-9]/g,'').length;const digits=normalizeChequeDigits(before).replace(/[^0-9]/g,'').slice(0,15);input.value=digits.replace(/\B(?=(\d{3})+(?!\d))/g,',');let position=0,count=0;while(position<input.value.length&&count<digitsBefore){if(/[0-9]/.test(input.value[position]))count++;position++;}input.setSelectionRange(position,position);};
 input.addEventListener('input',format);format();
});
let chequeDateTarget;
const chequeDateDialog=document.getElementById('chequeDateDialog');
const chequeDatePicker=document.getElementById('chequeDatePicker');
if(window.jalaliDatepicker){window.jalaliDatepicker.startWatch({selector:'#chequeDatePicker',container:'#chequeDateDialog',autoShow:false,time:false,persianDigits:false,hideAfterChange:true});}
document.querySelectorAll('[data-cheque-date]').forEach(input=>{
 const open=()=>{chequeDateTarget=input;chequeDatePicker.value=normalizeChequeDigits(input.value);chequeDateDialog.showModal();if(window.jalaliDatepicker)window.jalaliDatepicker.show(chequeDatePicker);};
 input.addEventListener('click',open);input.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open();}});
});
chequeDatePicker.addEventListener('jdp:change',()=>{if(chequeDateTarget){chequeDateTarget.value=normalizeChequeDigits(chequeDatePicker.value);chequeDateTarget.dispatchEvent(new Event('change',{bubbles:true}));}chequeDateDialog.close();});
chequeDateDialog.addEventListener('close',()=>{window.jalaliDatepicker?.hide();chequeDateTarget?.focus();});

const chequeRoot=document.querySelector('.scq');
let monthRequest;
async function loadChequeMonth(url,recordHistory=true){
    monthRequest?.abort();
    const controller=new AbortController();monthRequest=controller;
    const feedback=document.getElementById('calendarFeedback');
    document.getElementById('chequeCalendar').setAttribute('aria-busy','true');
    feedback.textContent='در حال دریافت ماه…';
    try {
        const response=await fetch(url,{signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        if(!response.ok)throw new Error('load');
        const page=new DOMParser().parseFromString(await response.text(),'text/html');
        const calendar=page.getElementById('chequeCalendar');const details=page.getElementById('dayDetails');
        if(!calendar||!details)throw new Error('invalid');
        document.getElementById('chequeCalendar').replaceWith(calendar);
        document.getElementById('dayDetails').replaceWith(details);
        const month=new URL(url,location.href).searchParams.get('month');
        if(month)document.querySelectorAll('[name="month"]').forEach(input=>input.value=month);
        if(recordHistory)history.pushState({chequeMonth:true},'',url);
        feedback.textContent='';
    } catch(error){if(error.name!=='AbortError')feedback.textContent='دریافت ماه انجام نشد؛ دوباره ماه قبل یا بعد را انتخاب کنید.';}
    finally{if(monthRequest===controller)document.getElementById('chequeCalendar').removeAttribute('aria-busy');}
}
chequeRoot.addEventListener('click',event=>{
    const monthLink=event.target.closest('.scq-nav a');
    if(monthLink&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&event.button===0){event.preventDefault();loadChequeMonth(monthLink.href);return;}
    const day=event.target.closest('[data-day]');
    if(day){document.getElementById('dayDetails').hidden=false;document.querySelectorAll('[data-entry-day]').forEach(row=>row.hidden=row.dataset.entryDay!==day.dataset.day);document.querySelectorAll('[data-day]').forEach(button=>button.classList.toggle('selected',button===day));document.getElementById('dayTitle').textContent='جزئیات '+day.dataset.dateLabel;document.getElementById('dayCoverage').textContent='کسری تجمعی تا این روز: '+day.dataset.shortfall;}
    if(event.target.closest('#showMonth')){document.querySelectorAll('[data-entry-day]').forEach(row=>row.hidden=false);document.getElementById('dayTitle').textContent='جزئیات این ماه';document.getElementById('dayCoverage').textContent='محاسبه بر اساس سوابق ثبت‌شده در این بخش است.';document.querySelectorAll('[data-day]').forEach(button=>button.classList.remove('selected'));}
});
window.addEventListener('popstate',()=>loadChequeMonth(location.href,false));

const depositSelect=document.getElementById('depositSelect');
const depositConfirm=document.getElementById('depositConfirm');
const depositNext=document.getElementById('depositNext');
function depositStage(confirm){depositSelect.hidden=confirm;depositConfirm.hidden=!confirm;document.getElementById('depositStep').textContent=confirm?'۲ از ۲ · ثبت خواباندن':'۱ از ۲ · انتخاب چک';}
document.querySelectorAll('[name="selected_cheque"]').forEach(radio=>radio.addEventListener('change',()=>{depositNext.disabled=false;document.getElementById('depositChequeId').value=radio.value;document.getElementById('selectedChequeCaption').textContent=radio.dataset.caption;}));
depositNext.addEventListener('click',()=>{depositStage(true);depositConfirm.querySelector('[name="effective_date"]').focus();});
document.getElementById('depositBack').addEventListener('click',()=>depositStage(false));
@if(old('kind')==='deposit' && old('cheque_id'))
const oldSelection=Array.from(document.querySelectorAll('[name="selected_cheque"]')).find(r=>r.value===document.getElementById('depositChequeId').value);
if(oldSelection){oldSelection.checked=true;depositNext.disabled=false;document.getElementById('selectedChequeCaption').textContent=oldSelection.dataset.caption;depositStage(true);}
@endif

@if(request('dialog')==='deposit' || old('kind')==='deposit')
document.getElementById('depositDialog').showModal();
@elseif(old('kind')==='issued')
document.getElementById('issuedDialog').showModal();
@endif
</script>
@endsection
