<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="hall-data-url" content="{{ $dataUrl }}">
    <meta name="hall-unlock-url" content="{{ route('hall.unlock', absolute: false) }}">
    <meta name="hall-refresh-seconds" content="{{ $refreshSeconds }}">
    <title>نبض آریا | داشبورد مدیریتی</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="{{ asset('hall-management/dashboard.css') }}?v={{ filemtime(public_path('hall-management/dashboard.css')) }}">
    <script type="module" src="{{ asset('hall-management/dashboard.js') }}?v={{ filemtime(public_path('hall-management/dashboard.js')) }}"></script>
</head>
<body>
<main class="dashboard" id="hall-dashboard">
    <header class="masthead">
        <div class="brand"><span class="brand-mark" aria-hidden="true">آ</span><div><h1>نبض آریا</h1><p>مدیریت زندهٔ فروش و عملیات</p></div></div>
        <div class="masthead-end"><div class="connection" id="connection" data-state="loading" role="status"><span class="connection-dot"></span><span id="connection-label">در حال دریافت اطلاعات</span></div><time id="wall-clock"></time><button class="header-button" id="fullscreen" type="button">تمام‌صفحه</button><form method="get" action="{{ route('hall.unlock') }}"><button type="submit" class="header-button">قفل نمایشگر</button></form></div>
    </header>
    <section class="metrics" aria-label="عملکرد امروز؛ همهٔ مبلغ‌ها به ریال">
        <div class="metric"><span>فروش قطعی امروز</span><div><strong id="sales-amount">—</strong><small>ریال</small></div><small id="sales-count">—</small></div>
        <div class="metric"><span>وصول نقد و واریز امروز</span><div><strong id="cash-amount">—</strong><small>ریال</small></div><small>مستقل از تاریخ صدور فاکتور</small></div>
        <div class="metric"><span>چک دریافت‌شدهٔ امروز</span><div><strong id="cheque-amount">—</strong><small>ریال</small></div><small id="cheque-count">—</small></div>
    </section>
    <section class="operations" aria-label="سفارش‌های در جریان">
        <div class="operations-heading"><h2>جریان سفارش‌ها <span id="document-total">—</span></h2><div class="board-controls"><span id="last-updated">هنوز به‌روز نشده</span><button type="button" id="pause" aria-pressed="false">توقف مرور</button><button type="button" id="next">ردیف بعد ↓</button></div></div>
        <div class="connection-alert" id="connection-alert" role="alert" hidden></div>
        <div class="board" id="board" aria-label="چهار صف سفارش‌ها">
            @foreach (['draft' => 'در حال ثبت', 'finance' => 'صف مالی', 'warehouse' => 'صف انبار', 'shipping' => 'صف ارسال'] as $key => $label)
            <section class="lane lane-{{ $key }}" data-stage="{{ $key }}" aria-label="{{ $label }}">
                <header class="lane-heading"><h3>{{ $label }}</h3><strong data-count>—</strong></header>
                <div class="lane-summary"><span>میانگین انتظار</span><span data-average>—</span></div>
                <div class="lane-window"><div class="lane-track"></div><p class="lane-empty">در حال دریافت…</p></div>
                <footer class="lane-footer"><span data-visible></span><span data-rotation></span></footer>
            </section>
            @endforeach
        </div>
    </section>
    <div class="event-bar" aria-live="polite"><span class="event-label">آخرین تغییر</span><span id="event-text">در انتظار اطلاعات</span></div>
    <footer class="page-footer"><span>شمارنده: ساعت:دقیقه:ثانیه · کل: از ثبت اولیه</span><span>قلم: ردیف کالا · زمان نامشخص: — · قرمز: تأخیر از حد تعیین‌شده</span></footer>
    <dialog id="document-detail"><div class="detail-heading"><h2>جزئیات سفارش</h2><button id="close-detail" type="button" aria-label="بستن جزئیات">×</button></div><dl id="detail-content"></dl><p>این نمایشگر فقط برای مشاهده است.</p></dialog>
</main>
</body>
</html>
