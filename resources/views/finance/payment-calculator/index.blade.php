<x-app-layout>
    <x-slot name="title">ماشین‌حساب نقد و چک</x-slot>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-payment-quick.css') }}?v={{ filemtime(public_path('css/finance-payment-quick.css')) }}">
    @endpush

    <section id="financeQuickCalc" class="pc-quick" dir="rtl"
       data-calculate-url="{{ route('finance.reports.payment-calculator.calculate') }}"
       data-print-url="{{ route('finance.reports.payment-calculator.print') }}"
       data-csrf="{{ csrf_token() }}"
       data-today-jalali="{{ $todayJalali }}">

 <div class="page-shell">
  <header class="masthead">
   <div class="identity">
     <div class="mark" aria-hidden="true">چ</div>
     <div><span class="eyebrow">گزارش‌های مالی</span><h1>ماشین‌حساب نقد و چک</h1></div>
   </div>
   <button id="openSettings" type="button" class="settings-link" title="تنظیمات عمومی نرخ و تاریخ محاسبه" aria-label="تنظیمات محاسبه">
     <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>
     <span>سود <b id="rateChip">۶٪</b></span>
   </button>
  </header>
  <main class="calculator" aria-label="محاسبه سریع شرایط فروش">
    <div class="lead"><div><h2>چی می‌خوای حساب کنی؟</h2><p>نوع محاسبه رو انتخاب کن؛ فقط مبلغ‌ها و تاریخ‌ها رو بنویس.</p></div><span class="demo-badge">فقط محاسبه، بدون ثبت سند</span></div>
    <div class="tabs" role="tablist" aria-label="نوع محاسبه">
      <button type="button" class="tab active" role="tab" id="tab-cheque" data-mode="cheque" aria-selected="true" aria-controls="inputPanel">مبلغ چک آخر</button>
      <button type="button" class="tab" role="tab" id="tab-cash" data-mode="cash" aria-selected="false" aria-controls="inputPanel">نقدی باقیمانده</button>
      <button type="button" class="tab" role="tab" id="tab-value" data-mode="value" aria-selected="false" aria-controls="inputPanel">ارزش نقدی چک‌ها</button>
    </div>
    <section id="inputPanel" class="body" role="tabpanel" aria-labelledby="tab-cheque">
      <p id="modeHelp" class="mode-help">قیمت نقدی و مبلغ پرداختی را وارد کن؛ سیستم مبلغ چک آخر را حساب می‌کند.</p>
      <div class="field-grid" id="priceBox">
        <label class="field"><span>قیمت نقدی جنس <em>ریال</em></span><input id="priceInput" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" value="100,000,000" placeholder="مثلاً 100,000,000"></label>
        <label class="field" id="cashField"><span id="cashLabel">نقدی مشتری، همین امروز <em>ریال</em></span><input id="cashInput" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" value="50,000,000" placeholder="0"></label>
      </div>
      <div id="cashExtra" class="cash-extra" hidden>
        <button id="showCash" type="button" class="text-action">+ مشتری قبلاً نقدی هم پرداخت کرده</button>
      </div>
      <section class="rows-block" id="knownSection" hidden>
        <div class="section-heading"><div><h3 id="knownTitle">چک‌هایی که مبلغ‌شان مشخص است</h3><p id="knownHint">اگر مشتری چک دیگری هم دارد، اینجا اضافه کن.</p></div></div>
        <div id="knownRows" class="row-list"></div>
      </section>
      <button type="button" id="addKnown" class="add-inline">+ افزودن چک با مبلغ مشخص</button>
      <section id="futureSection" class="rows-block">
        <div class="section-heading"><div><h3>چکی که مبلغش را نمی‌دانیم</h3><p>فقط تاریخ سررسید را مشخص کن.</p></div></div>
        <div id="futureRows" class="row-list"></div>
        <div class="bottom-tools"><button type="button" id="addFuture" class="add-inline">+ یک چک دیگر برای محاسبه</button><button type="button" id="toggleWeights" class="tiny-tool">تقسیم مبلغ چک‌ها <span id="weightArrow">⌄</span></button></div>
      </section>
      <div class="optional-line"><label for="customerInput">نام مشتری <span>(اختیاری)</span></label><input id="customerInput" type="text" maxlength="90" placeholder="مثلاً آقای یوسفی" autocomplete="off"></div>
      <div class="form-buttons"><button id="calculateBtn" type="button" class="calc-btn">محاسبه کن <span aria-hidden="true">←</span></button><button id="resetBtn" type="button" class="reset-btn">پاک کردن</button></div>
      <p id="errorBox" class="error" role="alert" hidden></p>
    </section>
    <section class="answer" aria-live="polite" aria-atomic="true">
      <div class="answer-top"><span class="status-dot" aria-hidden="true"></span><span id="answerLabel">مبلغ چک آخر</span><span class="answer-unit">ریال</span></div>
      <div class="big-answer" id="answerAmount">—</div>
      <p class="answer-hint" id="answerHint">محاسبه از سرور انجام می‌شود.</p>
      <div class="answer-stats"><div><span id="statOneLabel">جمع کل پرداخت‌ها</span><strong id="statOne">—</strong></div><div><span id="statTwoLabel">اختلاف با قیمت نقدی</span><strong id="statTwo">—</strong></div></div>
      <details id="detailsBox" class="details"><summary>ریز محاسبات و مبلغ هر چک <span class="caret">⌄</span></summary><div id="detailsRows" class="details-rows"></div><p id="formulaInfo" class="formula-info"></p></details>
      <button type="button" class="print-btn" id="printBtn" disabled>چاپ نتیجه / ذخیره PDF</button>
      <p class="read-only-note">فقط محاسبه است؛ هیچ سند یا چکی در سیستم ثبت نمی‌شود.</p>
    </section>
   </main>
   <footer class="footnote">همه مبلغ‌ها به ریال هستند؛ نرخ سود مشترک است و محاسبه با ماه ۳۰روزه انجام می‌شود.</footer>
  </div>
  <dialog id="settingsDialog" class="modal">
    <form method="dialog" id="settingsForm" class="modal-inner">
      <div class="modal-head"><div><h2>تنظیمات محاسبه</h2><p>یک‌بار تنظیم کن، در هر سه تب استفاده می‌شود.</p></div><button type="button" id="closeSettings" class="icon-close" aria-label="بستن">×</button></div>
      <label class="field"><span>سود ماهانه <em>درصد</em></span><input id="rateInput" type="text" inputmode="decimal" value="6" autocomplete="off"></label>
      <label class="field"><span>تاریخ مبنای محاسبه (امروز)</span><span class="date-wrap"><input id="baseDateInput" class="date-input" type="text" inputmode="numeric" value="{{ $todayJalali }}" autocomplete="off" aria-label="تاریخ مبنای شمسی"><button type="button" class="calendar-trigger" data-calendar-for="baseDateInput" aria-label="انتخاب تاریخ مبنا">▦</button></span></label>
      <p class="modal-note">این نرخ برای همین مرورگر ذخیره می‌شود و در هر سه تب استفاده خواهد شد. تاریخ‌ها شمسی و بدون ساعت هستند.</p>
      <div id="settingsError" class="error" hidden></div><button type="submit" value="ok" class="calc-btn">ذخیره تنظیمات</button>
    </form>
  </dialog>
  <dialog id="calendarDialog" class="calendar-modal" aria-label="انتخاب تاریخ شمسی">
    <div class="calendar-content"><div class="calendar-title"><h2>انتخاب تاریخ شمسی</h2><button type="button" id="calendarClose" class="icon-close" aria-label="بستن">×</button></div>
      <div class="month-nav"><button id="calPrev" type="button" aria-label="ماه قبل">›</button><strong id="calMonth">آبان ۱۴۰۵</strong><button id="calNext" type="button" aria-label="ماه بعد">‹</button></div>
      <div class="weekdays" aria-hidden="true"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div>
      <div class="days" id="calendarDays"></div>
      <p class="date-foot">هر تاریخ دلخواه را می‌توانی انتخاب کنی؛ حتی ۱۰ روز دیگر.</p>
      <button type="button" id="calendarToday" class="today-btn">انتخاب تاریخ مبنا</button>
    </div>
  </dialog>
  

    </section>
    @push('scripts')
        <script src="{{ asset('js/finance-payment-date-utils.js') }}?v={{ filemtime(public_path('js/finance-payment-date-utils.js')) }}" defer></script>
        <script src="{{ asset('js/finance-payment-quick-adapter.js') }}?v={{ filemtime(public_path('js/finance-payment-quick-adapter.js')) }}" defer></script>
        <script src="{{ asset('js/finance-payment-quick.js') }}?v={{ filemtime(public_path('js/finance-payment-quick.js')) }}" defer></script>
    @endpush
</x-app-layout>