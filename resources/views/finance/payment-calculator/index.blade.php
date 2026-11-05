<x-app-layout>
    <x-slot name="title">ماشین‌حساب نقد و چک</x-slot>

    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-payment-calculator.css') }}?v={{ filemtime(public_path('css/finance-payment-calculator.css')) }}">
    @endpush

    <section class="pc" id="pc-calculator" dir="rtl"
             data-calculate-url="{{ route('finance.reports.payment-calculator.calculate') }}"
             data-print-url="{{ route('finance.reports.payment-calculator.print') }}"
             data-first-due="{{ $firstDueJalali }}" data-second-due="{{ $secondDueJalali }}" data-third-due="{{ $thirdDueJalali }}">
        <header class="pc-heading">
            <div>
                <a href="{{ route('finance.reports.index') }}" class="pc-back">گزارش‌های مالی</a>
                <h1>ماشین‌حساب نقد و چک</h1>
                <p>شرایط پرداخت مشتری را وارد کنید و مبلغ هر چک و سود مؤثر را ببینید.</p>
            </div>
            <span class="pc-readonly">فقط محاسبه و گزارش · بدون ثبت سند</span>
        </header>

        <section class="pc-scenario-picker" aria-label="انتخاب نوع محاسبه">
            <h2>می‌خواهید چه چیزی را محاسبه کنید؟</h2>
            <div class="pc-modes" role="group" aria-label="نوع محاسبه">
                <button type="button" class="pc-mode is-active" data-pc-mode="schedule" aria-pressed="true">
                    <strong>مبلغ چک‌ها</strong><span>قیمت نقدی + پیش‌پرداخت ← چک‌های موردنیاز</span>
                </button>
                <button type="button" class="pc-mode" data-pc-mode="cash_needed" aria-pressed="false">
                    <strong>نقدی لازم امروز</strong><span>قیمت نقدی + چک‌های معلوم ← نقدی لازم</span>
                </button>
                <button type="button" class="pc-mode" data-pc-mode="cash_value" aria-pressed="false">
                    <strong>ارزش نقدی چک‌ها</strong><span>چک‌های مشتری ← ارزش جنس قابل فروش امروز</span>
                </button>
            </div>
            <p class="pc-mode-help" id="pc-mode-help">قیمت نقدی کالا و مبلغ‌های مشخص را بنویسید؛ تاریخ و وزن چک‌های باقیمانده را وارد کنید تا مبلغشان محاسبه شود.</p>
            <div class="pc-examples" role="group" aria-label="مثال‌های آماده">
                <span>نمونه سریع:</span>
                <button type="button" data-pc-example="schedule">۵۰ میلیون ریال نقد + چک</button>
                <button type="button" data-pc-example="last_cheque">۲۰ نقد + ۳۰ چک + چک آخر (میلیون ریال)</button>
                <button type="button" data-pc-example="cash_needed">۸۰ میلیون ریال چک؛ نقدی لازم؟</button>
                <button type="button" data-pc-example="cash_value">چک‌های ۲۰، ۳۰ و ۸۰ میلیون ریالی</button>
            </div>
            <p class="pc-hint">تمام ورودی‌ها و نتایج به ریال هستند؛ ۱۰۰ میلیون تومان معادل ۱٬۰۰۰٬۰۰۰٬۰۰۰ ریال است. محاسبات بدون ثبت مالی انجام می‌شوند.</p>
        </section>
        <form id="pc-form" class="pc-layout" autocomplete="off" novalidate>
            @csrf
            <div class="pc-fields">
                <article class="pc-panel">
                    <header class="pc-panel-head"><span class="pc-step">۱</span><div><h2>اطلاعات قرارداد</h2><p>مبلغ پایه و نرخ سود مورد توافق را وارد کنید.</p></div></header>
                    <div class="pc-inputs">
                        <label class="pc-field" id="pc-price-field">
                            <span id="pc-price-label">مبلغ پایه قرارداد <small>(ریال)</small></span>
                            <input id="pc-price" type="text" inputmode="numeric" data-rial placeholder="مثلاً ۱٬۰۰۰٬۰۰۰٬۰۰۰" required>
                        </label>
                        <label class="pc-field">
                            <span>سود ماهانه <small>(درصد)</small></span>
                            <input id="pc-rate" type="text" inputmode="decimal" placeholder="مثلاً ۶" required>
                        </label>
                        <label class="pc-field">
                            <span>تاریخ مبنای محاسبه <small>(شمسی)</small></span>
                            <input id="pc-base-date" type="text" inputmode="numeric" value="{{ $todayJalali }}" placeholder="۱۴۰۵/۰۸/۱۴" data-jdp data-jdp-only-date required>
                        </label>
                        <label class="pc-field">
                            <span>نام مشتری <small>(اختیاری؛ فقط نمایشی)</small></span>
                            <input id="pc-customer" type="text" maxlength="100" placeholder="نام مشتری یا شرح قرارداد">
                        </label>
                    </div>
                </article>

                <article class="pc-panel">
                    <header class="pc-panel-head"><span class="pc-step">۲</span><div><h2 id="pc-fixed-title">پیش‌پرداخت و مبالغ مشخص</h2><p id="pc-fixed-help">پرداخت نقدی یا چکی را که مبلغش از قبل معلوم است اضافه کنید.</p></div></header>
                    <div class="pc-table-scroll"><table class="pc-table"><thead><tr><th>نوع</th><th>تاریخ پرداخت</th><th>مبلغ (ریال)</th><th>حذف</th></tr></thead><tbody id="pc-fixed-body"></tbody></table></div>
                    <p id="pc-fixed-empty" class="pc-muted">هنوز پیش‌پرداختی اضافه نشده است.</p>
                    <button class="pc-add" id="pc-add-fixed" type="button">+ افزودن پیش‌پرداخت</button>
                </article>

                <article class="pc-panel" id="pc-weighted-panel">
                    <header class="pc-panel-head"><span class="pc-step">۳</span><div><h2>چک‌های قابل محاسبه</h2><p>تاریخ سررسید و وزن را مشخص کنید؛ مبلغ هر چک به‌صورت خودکار محاسبه می‌شود.</p></div></header>
                    <div class="pc-table-scroll"><table class="pc-table"><thead><tr><th>سررسید شمسی</th><th>وزن چک</th><th>مبلغ محاسبه‌شده</th><th>حذف</th></tr></thead><tbody id="pc-weighted-body"></tbody></table></div>
                    <button class="pc-add" id="pc-add-weighted" type="button">+ افزودن چک</button>
                    <p class="pc-hint">وزن مساوی یعنی ارزش فعلی برابر، نه لزوماً مبلغ اسمی برابر. ماه مالی بر اساس ۳۰ روز محاسبه می‌شود.</p>
                </article>

                <div class="pc-actions">
                    <button class="pc-primary" id="pc-submit" type="submit">محاسبه شرایط پرداخت</button>
                    <button class="pc-reset" id="pc-reset" type="button">پاک کردن فرم</button>
                </div>
            </div>

            <aside class="pc-result-panel" aria-live="polite" aria-atomic="false">
                <div class="pc-panel pc-result-card">
                    <h2>خلاصه گزارش</h2>
                    <p id="pc-result-status" class="pc-muted" role="status">بعد از ورود مبلغ و نرخ سود، نتیجه اینجا نمایش داده می‌شود.</p>
                    <div id="pc-result" hidden>
                        <p class="pc-customer-label" id="pc-result-customer"></p>
                        <div class="pc-answer"><span id="pc-answer-title">پاسخ محاسبه</span><strong id="pc-answer">—</strong><small>ریال</small><p id="pc-answer-help"></p></div>
                        <div class="pc-metric-main"><span>مجموع مبالغ پرداختی</span><strong id="pc-nominal">—</strong><small>ریال</small></div>
                        <div class="pc-metrics">
                            <div><span>مجموع پرداخت‌های با مبلغ مشخص</span><strong id="pc-fixed-total">—</strong></div>
                            <div><span>مجموع همه چک‌ها</span><strong id="pc-cheques-total">—</strong></div>
                            <div><span id="pc-increase-label">اختلاف با قیمت پایه</span><strong id="pc-increase">—</strong></div>
                            <div><span>درصد افزایش کل</span><strong id="pc-overall">—</strong></div>
                            <div><span>نرخ مؤثر روزانه</span><strong id="pc-daily">—</strong></div>
                            <div><span>ارزش فعلی پرداخت‌ها</span><strong id="pc-present">—</strong></div>
                        </div>
                        <h3>جدول مبالغ پرداخت</h3>
                        <div class="pc-table-scroll"><table class="pc-table pc-result-table"><thead><tr><th>نوع</th><th>سررسید</th><th>روز</th><th>مبلغ (ریال)</th></tr></thead><tbody id="pc-result-rows"></tbody></table></div>
                        <p class="pc-hint">مبالغ به ریال صحیح گرد شده‌اند. نتیجه صرفاً محاسبات پیشنهادی است و به معنی ثبت یا وصول چک نیست.</p>
                        <button type="button" id="pc-print" class="pc-print" disabled>چاپ گزارش / ذخیره PDF</button>
                    </div>
                    <div id="pc-error" class="pc-error" role="alert" hidden></div>
                </div>
            </aside>
        </form>
        <template id="pc-fixed-template"><tr class="pc-input-row">
            <td><select class="pc-kind" aria-label="نوع پیش‌پرداخت"><option value="cash">نقدی</option><option value="cheque">چک با مبلغ مشخص</option></select></td>
            <td><input class="pc-due" type="text" inputmode="numeric" data-jdp data-jdp-only-date aria-label="تاریخ پیش‌پرداخت شمسی" placeholder="۱۴۰۵/۰۸/۱۴"></td>
            <td><input class="pc-amount" type="text" inputmode="numeric" data-rial aria-label="مبلغ پیش‌پرداخت ریال" placeholder="مبلغ"></td>
            <td><button type="button" class="pc-remove" aria-label="حذف پیش‌پرداخت">×</button></td>
        </tr></template>
        <template id="pc-weighted-template"><tr class="pc-input-row">
            <td><input class="pc-due" type="text" inputmode="numeric" data-jdp data-jdp-only-date aria-label="تاریخ سررسید چک شمسی" placeholder="۱۴۰۵/۰۸/۱۴"></td>
            <td><input class="pc-weight" type="text" inputmode="decimal" aria-label="وزن چک" value="1"></td>
            <td class="pc-row-result">پس از محاسبه</td>
            <td><button type="button" class="pc-remove" aria-label="حذف چک">×</button></td>
        </tr></template>
    </section>

    @push('scripts')
        <script src="{{ asset('js/finance-payment-calculator.js') }}?v={{ filemtime(public_path('js/finance-payment-calculator.js')) }}" defer></script>
    @endpush
</x-app-layout>
