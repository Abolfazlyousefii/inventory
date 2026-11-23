@php($prefix='personnel.'.($kind==='leave'?'leaves':'reports'))
<section class="hr-list" id="hrEntryList" aria-label="فهرست درخواست‌ها">
<div class="hr-list-head"><strong>@include('personnel.workflows.icon',['name'=>'history']) {{ $team?'ارجاع‌های شما':'سوابق من' }}</strong><span>{{ $entries->total() }} مورد</span></div>
@forelse($entries as $item)
<article class="hr-row">
<span class="hr-row-icon {{ $kind==='leave'?'hr-teal':'hr-violet' }}" aria-hidden="true">@include('personnel.workflows.icon',['name'=>$kind])</span>
<div class="hr-row-title"><a href="{{ route($prefix.'.show',$item->id) }}" data-hr-detail>{{ $item->title ?: 'گزارش کار' }}</a><small>{{ $team?$item->user?->name:'شماره '.$item->id }} · {{ \App\Services\Personnel\PersonnelDates::display($item->created_at,'Y/m/d H:i') }}</small></div>
<div class="hr-row-period">@if($kind==='leave'){{ \App\Services\Personnel\PersonnelDates::display($item->start_date) }}<small>تا {{ \App\Services\Personnel\PersonnelDates::display($item->end_date) }}@if(($item->payload['leave_unit']??'')==='ساعتی') · {{ $item->payload['start_time'] }}–{{ $item->payload['end_time'] }}@endif</small>@else <span>گزارش فعالیت</span><small>{{ $item->submitted_at?'ارسال: '.\App\Services\Personnel\PersonnelDates::display($item->submitted_at):'هنوز ارسال نشده' }}</small>@endif</div>
<div><span class="hr-status hr-status-{{ $item->status }}">{{ $item->label() }}</span>@if($kind==='leave' && ($current=$item->steps->firstWhere('status','pending')))<small class="hr-pending-name">نزد {{ $current->approver_name }}</small>@endif</div>
<a class="hr-button hr-secondary hr-small" data-hr-detail href="{{ route($prefix.'.show',$item->id) }}">@include('personnel.workflows.icon',['name'=>'eye']) جزئیات</a>
</article>
@empty<div class="hr-empty"><span aria-hidden="true">@include('personnel.workflows.icon',['name'=>$kind])</span><h3>{{ $team?'درخواستی برای بررسی ندارید':'هنوز موردی ثبت نشده است' }}</h3><p>{{ $team?'موارد ارجاع‌شده طبق ساختار سازمانی در این بخش نمایش داده می‌شوند.':'از دکمهٔ بالای صفحه برای ثبت اولین مورد استفاده کنید.' }}</p></div>@endforelse
<div class="hr-pagination">{{ $entries->links() }}</div>
</section>
