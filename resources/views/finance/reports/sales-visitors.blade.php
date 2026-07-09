<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">گزارش فروش ویزیتورها</h2></x-slot>

    @php $money = fn($value) => number_format((int) $value) . ' ریال'; @endphp

    <div class="py-8" dir="rtl">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-col gap-3 rounded-3xl bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-black text-blue-700">گزارش فروش ویزیتورها</h1>
                    <p class="mt-1 text-sm text-gray-500">مبلغ فروش از snapshot فاکتور و مبلغ پرداختی از پرداخت‌های ثبت‌شده خوانده می‌شود.</p>
                    @unless($creatorColumn)
                        <p class="mt-2 text-xs text-amber-600">ستون created_by روی جدول فاکتورها موجود نیست؛ همه فاکتورها در گروه «نامشخص» نمایش داده شده‌اند.</p>
                    @endunless
                </div>
                <a href="{{ route('finance.reports.index', request()->query()) }}" class="rounded-xl border border-blue-200 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-50">بازگشت به گزارش مالی</a>
            </div>

            <form method="GET" action="{{ route('finance.reports.sales-visitors') }}" class="grid gap-3 rounded-3xl bg-white p-4 shadow-sm md:grid-cols-6">
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="rounded-xl border-gray-300">
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="rounded-xl border-gray-300">
                <select name="user_id" class="rounded-xl border-gray-300" @disabled(! $creatorColumn)>
                    <option value="">همه کاربران</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['user_id'] === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                <select name="customer_id" class="rounded-xl border-gray-300">
                    <option value="">همه مشتریان</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected($filters['customer_id'] === $customer->id)>{{ $customer->display_name ?: ('مشتری #' . $customer->id) }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-xl border-gray-300">
                    <option value="">همه وضعیت‌های معتبر</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="rounded-xl bg-blue-600 px-4 py-2 font-bold text-white hover:bg-blue-700">اعمال فیلتر</button>
            </form>

            <div class="overflow-hidden rounded-3xl bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-blue-50 text-blue-900">
                            <tr>
                                <th class="px-4 py-3">ویزیتور / کاربر</th>
                                <th class="px-4 py-3">تعداد فاکتور</th>
                                <th class="px-4 py-3">تعداد مشتری</th>
                                <th class="px-4 py-3">جمع فروش</th>
                                <th class="px-4 py-3">جمع پرداخت‌شده</th>
                                <th class="px-4 py-3">مانده</th>
                                <th class="px-4 py-3">میانگین فروش</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($rows as $row)
                                <tr class="hover:bg-blue-50/40">
                                    <td class="px-4 py-3 font-bold text-gray-800">{{ $row['user_name'] }}</td>
                                    <td class="px-4 py-3">{{ number_format($row['invoice_count']) }}</td>
                                    <td class="px-4 py-3">{{ number_format($row['customers_count']) }}</td>
                                    <td class="px-4 py-3 text-blue-700 font-bold">{{ $money($row['total_sales']) }}</td>
                                    <td class="px-4 py-3 text-emerald-600 font-bold">{{ $money($row['paid_amount']) }}</td>
                                    <td class="px-4 py-3 text-amber-600 font-bold">{{ $money($row['remaining_amount']) }}</td>
                                    <td class="px-4 py-3">{{ $money($row['average_sale']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">داده‌ای برای فیلترهای انتخاب‌شده یافت نشد.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50 font-black text-gray-900">
                            <tr>
                                <td class="px-4 py-3">جمع کل</td>
                                <td class="px-4 py-3">{{ number_format($totals['invoice_count']) }}</td>
                                <td class="px-4 py-3">{{ number_format($totals['customers_count']) }}</td>
                                <td class="px-4 py-3">{{ $money($totals['total_sales']) }}</td>
                                <td class="px-4 py-3">{{ $money($totals['paid_amount']) }}</td>
                                <td class="px-4 py-3">{{ $money($totals['remaining_amount']) }}</td>
                                <td class="px-4 py-3">{{ $money($totals['average_sale']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
