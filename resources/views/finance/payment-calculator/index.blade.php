<x-app-layout>
    <x-slot name="title">ماشین‌حساب نقد و چک</x-slot>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-payment-quick.css') }}?v={{ filemtime(public_path('css/finance-payment-quick.css')) }}">
    @endpush

    <section id="financeQuickCalc" class="pc-quick pc-v2" dir="rtl"
       data-calculate-url="{{ route('finance.reports.payment-calculator.calculate') }}"
       data-print-url="{{ route('finance.reports.payment-calculator.print') }}"
       data-csrf="{{ csrf_token() }}"
       data-today-jalali="{{ $todayJalali }}">


 <div class="page-shell">
  <main class="calculator" aria-label="محاسبه شرایط پرداخت">
   <div class="control-strip">
    <div class="calculation-context" aria-label="تنظیمات محاسبه">
      <span>نرخ ماهانه <button type="button" id="rateChip" class="rate-chip-button" aria-controls="settingsDialog" aria-haspopup="dialog" aria-label="تغییر نرخ سود ماهانه" title="تغییر نرخ سود ماهانه">۶٪</button></span>
      <span>تاریخ مبنا <b id="baseDateChip">{{ $todayJalali }}</b></span>
    </div>
    <button type="button" id="openSettings" class="settings-link" aria-controls="settingsDialog" aria-haspopup="dialog">تنظیم نرخ و تاریخ</button>
   </div>

   <div class="tabs" role="tablist" aria-label="نوع محاسبه">
    <button type="button" class="tab active" role="tab" id="tab-cheque" data-mode="cheque" aria-selected="true" aria-controls="inputPanel">محاسبه مبلغ چک</button>
    <button type="button" class="tab" role="tab" id="tab-cash" data-mode="cash" aria-selected="false" aria-controls="inputPanel">محاسبه نقد لازم</button>
    <button type="button" class="tab" role="tab" id="tab-value" data-mode="value" aria-selected="false" aria-controls="inputPanel">ارزش نقدی چک‌ها</button>
   </div>

   <div class="calc-workspace">
    <section id="inputPanel" class="body" role="tabpanel" aria-labelledby="tab-cheque">
     <div class="input-head">
      <h2>شرایط پرداخت</h2>
      <p id="modeHelp" class="mode-help">مبلغ و تاریخ چک را وارد کنید.</p>
     </div>

     <div class="field-grid" id="priceBox">
      <label class="field"><span>قیمت نقدی کالا <em>ریال</em></span><input id="priceInput" data-money type="text" inputmode="numeric" dir="ltr" autocomplete="off" spellcheck="false" value="" placeholder="مبلغ را وارد کنید"></label>
      <label class="field" id="cashField"><span id="cashLabel">پیش‌پرداخت <em>ریال</em></span><input id="cashInput" data-money type="text" inputmode="numeric" dir="ltr" autocomplete="off" spellcheck="false" value="" placeholder="اختیاری"></label>
     </div>
     <div id="cashExtra" class="cash-extra" hidden><button id="showCash" type="button" class="text-action">+ افزودن نقد دریافتی</button></div>

     <div class="cheque-editor">
      <div class="cheque-heading">
       <h3>چک‌های مشتری</h3>
       <button type="button" id="addPayment" class="add-inline">+ افزودن چک</button>
      </div>
      <p id="emptyCheques" class="empty-cheques">هنوز چکی وارد نشده است.</p>
      <section class="rows-block" id="knownSection" hidden>
       <div class="section-heading"><h3 id="knownTitle">چک‌های با مبلغ مشخص</h3><p id="knownHint" hidden></p></div>
       <div id="knownRows" class="row-list"></div>
      </section>
      <button type="button" id="addKnown" class="add-inline" hidden>+ افزودن چک</button>
      <section id="futureSection" class="rows-block" hidden>
       <div class="section-heading"><h3>چک‌های محاسبه‌شونده</h3></div>
       <div id="futureRows" class="row-list"></div>
       <div class="bottom-tools">
        <button type="button" id="addFuture" class="add-inline" hidden>+ افزودن چک</button>
        <button type="button" id="toggleWeights" class="tiny-tool" hidden aria-expanded="false">تقسیم مبلغ چک‌ها <span id="weightArrow">⌄</span></button>
       </div>
      </section>
     </div>

     <details class="optional-details">
      <summary>نام مشتری برای چاپ (اختیاری)</summary>
      <div class="optional-line"><label for="customerInput">نام مشتری</label><input id="customerInput" type="text" maxlength="90" placeholder="نام مشتری" autocomplete="off"></div>
     </details>
     <div class="form-buttons">
      <button id="calculateBtn" type="button" class="calc-btn">محاسبه</button>
      <button id="resetBtn" type="button" class="reset-btn">پاک کردن فرم</button>
     </div>
     <p id="errorBox" class="error" role="alert" hidden></p>
    </section>

    <section class="answer" aria-live="polite" aria-atomic="true">
     <h2 class="result-heading">خلاصه محاسبه</h2>
     <div class="answer-top"><span id="answerLabel">مبلغ چک پیشنهادی</span></div>
     <div class="answer-value"><div class="big-answer" id="answerAmount">—</div><span class="answer-unit">ریال</span></div>
     <p class="answer-hint" id="answerHint">اطلاعات را وارد کنید تا نتیجه نمایش داده شود.</p>
     <div class="answer-stats">
      <div class="stat-cash"><span>نقد پرداختی</span><strong id="cashStat">—</strong></div>
      <div class="stat-cheques"><span>جمع چک‌ها</span><strong id="chequeStat">—</strong></div>
      <div class="stat-total"><span id="statOneLabel">جمع کل دریافتی‌ها</span><strong id="statOne">—</strong></div>
      <div class="stat-increase"><span id="statTwoLabel">افزایش نسبت به نقد</span><strong id="statTwo">—</strong></div>
     </div>
     <details id="detailsBox" class="details">
      <summary>مشاهده ریز محاسبات</summary>
      <div id="detailsRows" class="details-rows"></div>
      <p id="formulaInfo" class="formula-info"></p>
     </details>
     <button type="button" class="print-btn" id="printBtn" disabled>چاپ گزارش</button>
     <p class="read-only-note">صرفاً برای محاسبه؛ سندی ثبت نمی‌شود.</p>
    </section>
   </div>
  </main>
 </div>
  <dialog id="settingsDialog" class="pc-settings-dialog" aria-labelledby="settingsDialogTitle">
    <form method="dialog" id="settingsForm" class="modal-inner">
      <div class="modal-head"><div><h2 id="settingsDialogTitle">تنظیمات محاسبه</h2><p>یک‌بار تنظیم کن، در هر سه تب استفاده می‌شود.</p></div><button type="button" id="closeSettings" class="icon-close" aria-label="بستن">×</button></div>
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
