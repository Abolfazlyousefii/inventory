<x-app-layout>
    <x-slot name="title">گزارش فروش ویزیتورها</x-slot>

    @php
        $money = fn ($value) => number_format((int) $value) . ' ریال';
        $financeReportsCss = public_path('css/finance-reports.css');
    @endphp

    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-reports.css') }}?v={{ is_file($financeReportsCss) ? filemtime($financeReportsCss) : 1 }}">
    @endpush

    @push('scripts')
        <script>
            if (window.jalaliDatepicker) {
                window.jalaliDatepicker.startWatch({
                    selector: '#visitor-date-from, #visitor-date-to',
                    persianDigits: true,
                    time: false,
                    hideAfterChange: true,
                    zIndex: 3000
                });
            }
        </script>
    @endpush

    <div class="finance-report-page">
        <div class="finance-report-page__stack">
            <header class="finance-report-header">
                <div>
                    <h1 class="finance-report-header__title">گزارش فروش ویزیتورها</h1>
                    <p class="finance-report-header__subtitle">فروش، پرداخت‌شده و مانده به تفکیک ثبت‌کننده فاکتور</p>
                </div>
                <a href="{{ route('finance.reports.index') }}" class="finance-report-button finance-report-button--secondary">
                    بازگشت به گزارش مالی
                </a>
            </header>

            <section class="finance-report-panel" aria-labelledby="visitor-filter-title">
                <h2 class="finance-report-panel__title" id="visitor-filter-title">فیلتر گزارش</h2>

                <form method="GET" action="{{ route('finance.reports.sales-visitors') }}" class="finance-report-filter finance-report-filter--visitors">
                    <div class="finance-report-field">
                        <label for="visitor-date-from">از تاریخ</label>
                        <input id="visitor-date-from" type="text" name="date_from" value="{{ $filters['date_from'] }}" data-jdp data-jdp-only-date inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۴/۱۰" dir="ltr">
                    </div>

                    <div class="finance-report-field">
                        <label for="visitor-date-to">تا تاریخ</label>
                        <input id="visitor-date-to" type="text" name="date_to" value="{{ $filters['date_to'] }}" data-jdp data-jdp-only-date inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۵/۱۰" dir="ltr">
                    </div>

                    <div class="finance-report-field">
                        <label for="visitor-user">فروشنده</label>
                        <select id="visitor-user" name="user_id">
                            <option value="">همه کاربران</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" @selected($filters['user_id'] === $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="finance-report-field">
                        <label for="visitor-customer">مشتری</label>
                        <select id="visitor-customer" name="customer_id">
                            <option value="">همه مشتریان</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected($filters['customer_id'] === $customer->id)>
                                    {{ $customer->display_name ?: ('مشتری #' . $customer->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="finance-report-filter__actions">
                        <button type="submit" class="finance-report-button finance-report-button--primary">اعمال فیلتر</button>
                        <a href="{{ route('finance.reports.sales-visitors') }}" class="finance-report-button finance-report-button--secondary">پاک‌کردن</a>
                    </div>
                </form>
            </section>

            <section class="finance-report-panel" aria-labelledby="visitor-table-title">
                <h2 class="finance-report-panel__title" id="visitor-table-title">نتیجه گزارش</h2>

                @if($rows->isEmpty())
                    <div class="finance-report-empty">
                        <strong>برای فیلترهای انتخاب‌شده گزارشی یافت نشد.</strong>
                        <p>بازه تاریخ یا فروشنده را تغییر دهید و دوباره جست‌وجو کنید.</p>
                    </div>
                @else
                    <div class="finance-report-table-wrap">
                        <table class="finance-report-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>نام فروشنده</th>
                                    <th>تعداد فاکتور</th>
                                    <th>تعداد مشتری</th>
                                    <th>جمع فروش</th>
                                    <th>جمع پرداخت‌شده</th>
                                    <th>مانده</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td class="finance-report-table__seller">{{ $row['user_name'] }}</td>
                                        <td>{{ number_format($row['invoice_count']) }}</td>
                                        <td>{{ number_format($row['customers_count']) }}</td>
                                        <td class="finance-report-table__number finance-report-table__sales">{{ $money($row['total_sales']) }}</td>
                                        <td class="finance-report-table__number finance-report-table__paid">{{ $money($row['paid_amount']) }}</td>
                                        <td class="finance-report-table__number finance-report-table__remaining">{{ $money($row['remaining_amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2">جمع کل</td>
                                    <td>{{ number_format($totals['invoice_count']) }}</td>
                                    <td>{{ number_format($totals['customers_count']) }}</td>
                                    <td class="finance-report-table__number">{{ $money($totals['total_sales']) }}</td>
                                    <td class="finance-report-table__number">{{ $money($totals['paid_amount']) }}</td>
                                    <td class="finance-report-table__number">{{ $money($totals['remaining_amount']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </section>

            @if($filters['user_id'] && $detailRows->isNotEmpty())
                <section class="finance-report-panel" aria-labelledby="visitor-details-title">
                    <h2 class="finance-report-panel__title" id="visitor-details-title">جزئیات فاکتورهای فروشنده</h2>
                    <form method="POST" action="{{ route('finance.reports.sales-visitors.commission-batches.store') }}">
                        @csrf
                        <input type="hidden" name="visitor_id" value="{{ $filters['user_id'] }}">
                        <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
                        <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
                        <div class="finance-report-table-wrap">
                            <table class="finance-report-table">
                                <thead><tr><th>انتخاب</th><th>شماره فاکتور</th><th>تاریخ</th><th>فروشنده</th><th>مشتری</th><th>مبلغ</th></tr></thead>
                                <tbody>
                                @foreach($detailRows as $invoice)
                                    <tr>
                                        <td><input type="checkbox" name="invoice_ids[]" value="{{ $invoice->id }}" @disabled($batchedInvoiceIds->contains($invoice->id))></td>
                                        <td>{{ $invoice->uuid }}</td>
                                        <td>{{ App\Support\JalaliDate::dateTime($invoice->display_document_date) }}</td>
                                        <td>{{ $invoice->effectiveSeller()?->name ?? '—' }}</td>
                                        <td>{{ $invoice->customer_name ?: '—' }}</td>
                                        <td class="finance-report-table__number">{{ $money($invoice->total) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="finance-report-filter__actions mt-3">
                            <button type="submit" class="finance-report-button finance-report-button--primary">تأیید پورسانت فاکتورهای انتخاب‌شده</button>
                        </div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
