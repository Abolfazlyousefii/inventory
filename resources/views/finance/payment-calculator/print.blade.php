<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>گزارش شرایط پرداخت | ماشین‌حساب نقد و چک</title>
    <link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf2f6; color: #14354b; font-family: Vazirmatn, Tahoma, sans-serif; font-size: 12px; line-height: 1.9; }
        .toolbar { max-width: 980px; display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 20px auto; padding: 0 10px; }
        .toolbar p { margin: 0; color: #667b8c; }
        button { cursor: pointer; font: inherit; padding: 10px 20px; background: #2879a8; color: white; border: 0; border-radius: 8px; }
        .page { max-width: 980px; min-height: 680px; padding: 35px 40px; margin: 0 auto 30px; background: white; box-shadow: 0 8px 30px #17394e19; border-top: 5px solid #2879a8; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #dce5eb; padding-bottom: 20px; gap: 15px; }
        header h1 { font-size: 22px; margin: 0 0 5px; }
        header p { margin: 0; color: #627889; }
        .head-aside { text-align: left; font-size: 11px; color: #627889; }
        h2 { font-size: 15px; margin: 24px 0 11px; }
        .info, .stats { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
        .calculation-info { grid-template-columns: repeat(2, minmax(0,1fr)); }
        .calculation-info .item:last-child { grid-column: 1 / -1; }
        .item { border: 1px solid #dce5eb; border-radius: 8px; padding: 12px 14px; break-inside: avoid; }
        .item .label { color: #6a7f8d; display: block; margin-bottom: 4px; font-size: 11px; }
        .item strong { font-size: 15px; font-weight: 750; overflow-wrap: anywhere; }
        .item.primary { background: #eaf4f8; border-color: #b9d6e5; }
        .item.primary strong { color: #164e75; font-size: 19px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { text-align: right; border: 1px solid #dce5eb; padding: 10px 8px; font-size: 11px; overflow-wrap: anywhere; }
        th { color: #274c63; background: #f1f7fa; font-weight: 700; }
        tbody tr:nth-child(even) { background: #f9fcfe; }
        td.number, th.number { direction: ltr; text-align: left; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .total { font-weight: 800; background: #eaf4f8; }
        .note { margin-top: 24px; padding: 12px; background: #f6f9fb; border-right: 3px solid #2879a8; color: #527083; font-size: 11px; }
        footer { margin-top: 25px; border-top: 1px solid #dce5eb; padding-top: 14px; font-size: 11px; color: #738796; }
        @page { size: A4; margin: 15mm; }
        @media print { body { background: white; print-color-adjust: exact; -webkit-print-color-adjust: exact; } .toolbar { display: none; } .page { max-width: none; min-height: 0; box-shadow: none; border-top: 0; margin: 0; padding: 0; } }
        @media (max-width: 600px) { .toolbar { display: block; text-align: center; } .page { padding: 18px; } .info, .stats { grid-template-columns: repeat(2,minmax(0,1fr)); } header { display: block; } .head-aside { text-align: right; margin-top: 10px; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>گزارش قابل چاپ است؛ برای ذخیره PDF، در پنجره چاپ گزینه «Save as PDF» را انتخاب کنید.</p>
        <button type="button" onclick="window.print()">چاپ / ذخیره PDF</button>
    </div>
    <main class="page">
        <header>
            <div>
                <h1>گزارش محاسبه شرایط پرداخت</h1>
                <p>{{ config('app.name', 'ERP') }} · ماشین‌حساب نقد و چک</p>
            </div>
            <div class="head-aside">
                <div>تاریخ تهیه: {{ $printedAt }}</div>
                <div>مبنای مبلغ: ریال</div>
                <div>وضعیت: محاسبه پیشنهادی</div>
            </div>
        </header>

        <h2>{{ $scenarioTitle }}</h2>
        <section class="info">
            <div class="item primary"><span class="label">{{ $answerTitle }} (ریال)</span><strong>{{ number_format($answerAmount) }}</strong></div>
            <div class="item"><span class="label">جمع اسمی پرداخت‌ها (ریال)</span><strong>{{ number_format($result['total_nominal_rial']) }}</strong></div>
            <div class="item"><span class="label">مبنای محاسبه</span><strong>سود مرکب با ماه ۳۰ روزه</strong></div>
        </section>
        <h2>اطلاعات محاسبه</h2>
        <section class="info calculation-info">
            <div class="item"><span class="label">تاریخ مبنا (شمسی)</span><strong>{{ $baseDate }}</strong></div>
            <div class="item"><span class="label">{{ $mode === 'cash_value' ? 'ارزش نقدی معادل (ریال)' : 'مبلغ پایه قرارداد (ریال)' }}</span><strong>{{ number_format($result['total_price_rial']) }}</strong></div>
            <div class="item"><span class="label">سود ماهانه</span><strong>{{ number_format($result['monthly_rate'] * 100, 4) }}٪</strong></div>
            <div class="item"><span class="label">نرخ مؤثر روزانه</span><strong>{{ number_format($result['daily_rate'] * 100, 4) }}٪</strong></div>
            <div class="item"><span class="label">مبنای تبدیل نرخ</span><strong>ماه ۳۰ روزه</strong></div>
        </section>

        <h2>جدول شرایط پرداخت</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 7%">ردیف</th>
                    <th style="width: 24%">نوع پرداخت</th>
                    <th style="width: 19%">تاریخ سررسید</th>
                    <th style="width: 10%">روز</th>
                    <th style="width: 12%">وزن</th>
                    <th class="number" style="width: 28%">مبلغ (ریال)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $index => $row)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['due_date'] }}</td>
                        <td>{{ $row['day'] }}</td>
                        <td>{{ $row['weight'] === null ? '—' : number_format($row['weight'], 4, '.', ',') }}</td>
                        <td class="number">{{ number_format($row['amount_rial']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">پرداختی ثبت نشده است.</td></tr>
                @endforelse
                <tr class="total">
                    <td colspan="5">مجموع مبالغ پرداخت</td>
                    <td class="number">{{ number_format($result['total_nominal_rial']) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>خلاصه مالی</h2>
        <section class="stats">
            <div class="item primary"><span class="label">مجموع پرداخت‌ها (ریال)</span><strong>{{ number_format($result['total_nominal_rial']) }}</strong></div>
            <div class="item"><span class="label">مجموع مبالغ مشخص (ریال)</span><strong>{{ number_format($result['fixed_total_rial']) }}</strong></div>
            <div class="item"><span class="label">{{ match ($mode) { 'cash_needed' => 'نقدی محاسبه‌شده (ریال)', 'cash_value' => 'ارزش نقدی امروز (ریال)', default => 'مجموع چک‌های محاسبه‌شده (ریال)' } }}</span><strong>{{ number_format(match ($mode) { 'cash_needed' => $result['calculated_cash_rial'], 'cash_value' => $result['cash_equivalent_rial'], default => $result['flexible_total_rial'] }) }}</strong></div>
            <div class="item"><span class="label">{{ $mode === 'cash_value' ? 'اختلاف مبلغ اسمی و ارزش نقدی (ریال)' : 'اختلاف مبلغ با قیمت پایه (ریال)' }}</span><strong>{{ number_format($result['nominal_increase_rial']) }}</strong></div>
            <div class="item"><span class="label">درصد افزایش کل</span><strong>{{ number_format($result['overall_rate'] * 100, 2) }}٪</strong></div>
            <div class="item"><span class="label">ارزش فعلی پرداخت‌ها (ریال)</span><strong>{{ number_format(round($result['present_value_rounded_rial'])) }}</strong></div>
        </section>
        <div class="note">
            این گزارش صرفاً نتیجه محاسبات پیشنهادی است؛ سند حسابداری یا تأیید وصول چک محسوب نمی‌شود.
            مقادیر چک به ریال صحیح گرد شده‌اند؛ بنابراین ارزش فعلی جمع پرداخت‌های گرد شده ممکن است تفاوت جزئی داشته باشد.
            @if ($mode === 'schedule')
                وزن مساوی به معنای ارزش فعلی مساوی است و لزوماً مبلغ اسمی چک‌ها مساوی نیست.
            @else
                ارزش امروز چک‌ها از تنزیل مبلغ هر پرداخت تا تاریخ مبنا محاسبه شده است.
            @endif
        </div>
        <footer>تهیه‌شده توسط ماژول محاسبه‌گر نقد و چک ERP · فاقد ثبت مالی و تغییر در اسناد اصلی</footer>
    </main>
</body>
</html>
