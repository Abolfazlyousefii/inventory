@php $countFreezeState=app(\App\Services\InventoryCountFreezeService::class)->state(); @endphp
@if($countFreezeState?->enabled)
<div class="alert alert-warning" role="status" dir="rtl" style="border-right:4px solid #c88b35;margin-bottom:16px">
    <strong>انبارگردانی فعال است:</strong>
    {{$countFreezeState->all_products?'تمام کالاهای انبار مرکزی':\App\Models\Category::whereIn('id',json_decode($countFreezeState->category_ids??'[]',true))->pluck('name')->join('، ')}}
    <span>— پیش‌نویس بدون رزرو قابل ثبت است؛ عملیات موجودی این گروه‌ها و تأیید مالی آن‌ها تا پایان شمارش بسته است.</span>
    @if(auth()->user()?->isAdministrator())<a href="{{route('inventory-count-freeze.index')}}">مدیریت انبارگردانی</a>@endif
</div>
@endif
