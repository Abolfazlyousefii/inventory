<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">گزارش مالی</h2></x-slot>

    @php
        $money = fn($value) => number_format((int) $value) . ' ریال';
    @endphp

    <div class="py-8" dir="rtl">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="rounded-3xl bg-gradient-to-l from-blue-700 to-blue-500 text-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">داشبورد گزارش مالی</h1>
                        <p class="mt-2 text-blue-50">نمای read-only از فروش، پرداخت‌ها و مانده‌ها بر اساس فاکتورهای معتبر.</p>
                    </div>
                    <a href="{{ route('finance.reports.sales-visitors', request()->query()) }}" class="inline-flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-bold text-blue-700 shadow-sm hover:bg-blue-50">
                        گزارش فروش ویزیتورها
                    </a>
                </div>
            </div>

            <form method="GET" action="{{ route('finance.reports.index') }}" class="grid gap-3 rounded-3xl bg-white p-4 shadow-sm md:grid-cols-5">
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="rounded-xl border-gray-300" placeholder="از تاریخ">
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="rounded-xl border-gray-300" placeholder="تا تاریخ">
                <select name="status" class="rounded-xl border-gray-300">
                    <option value="">همه وضعیت‌های معتبر</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="customer_id" class="rounded-xl border-gray-300">
                    <option value="">همه مشتریان</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected($filters['customer_id'] === $customer->id)>{{ $customer->display_name ?: ('مشتری #' . $customer->id) }}</option>
                    @endforeach
                </select>
                <button class="rounded-xl bg-blue-600 px-4 py-2 font-bold text-white hover:bg-blue-700">اعمال فیلتر</button>
            </form>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-3xl bg-white p-5 shadow-sm border border-blue-50"><div class="text-sm text-gray-500">جمع فروش</div><div class="mt-3 text-xl font-black text-blue-700">{{ $money($summary['total_sales']) }}</div></div>
                <div class="rounded-3xl bg-white p-5 shadow-sm border border-blue-50"><div class="text-sm text-gray-500">جمع پرداخت‌شده</div><div class="mt-3 text-xl font-black text-emerald-600">{{ $money($summary['paid_amount']) }}</div></div>
                <div class="rounded-3xl bg-white p-5 shadow-sm border border-blue-50"><div class="text-sm text-gray-500">مانده کل</div><div class="mt-3 text-xl font-black text-amber-600">{{ $money($summary['remaining_amount']) }}</div></div>
                <div class="rounded-3xl bg-white p-5 shadow-sm border border-blue-50"><div class="text-sm text-gray-500">تعداد فاکتور</div><div class="mt-3 text-xl font-black text-gray-800">{{ number_format($summary['invoice_count']) }}</div></div>
                <div class="rounded-3xl bg-white p-5 shadow-sm border border-blue-50"><div class="text-sm text-gray-500">تعداد مشتری</div><div class="mt-3 text-xl font-black text-gray-800">{{ number_format($summary['customers_count']) }}</div></div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <a href="{{ route('finance.reports.sales-visitors', request()->query()) }}" class="rounded-3xl bg-white p-6 shadow-sm border border-blue-100 hover:border-blue-300">
                    <div class="text-lg font-black text-blue-700">گزارش فروش ویزیتورها</div>
                    <p class="mt-2 text-sm text-gray-500">گروه‌بندی فروش بر اساس کاربر ثبت‌کننده فاکتور، با پرداخت و مانده.</p>
                </a>
                @if($chequeSummary)
                    <div class="rounded-3xl bg-white p-6 shadow-sm border border-blue-100">
                        <div class="text-lg font-black text-blue-700">چک‌های در جریان</div>
                        <p class="mt-2 text-sm text-gray-500">{{ number_format($chequeSummary['count']) }} چک، جمع مبلغ: {{ $money($chequeSummary['amount']) }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
