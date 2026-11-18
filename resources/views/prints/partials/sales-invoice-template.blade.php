@php
    $fmtDate = function ($value, string $format = 'Y/m/d H:i') {
        if (!$value) return '—';
        try { return \Morilog\Jalali\Jalalian::fromDateTime($value)->format($format); } catch (\Throwable $e) { return (string) $value; }
    };
    $fa = fn ($value) => strtr((string) $value, array_combine(str_split('0123456789'), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)));
    $num = fn ($value) => $fa(str_replace(',', '٬', number_format((int) $value)));
    $money = fn ($value) => new \Illuminate\Support\HtmlString('<span class="print-money" dir="rtl"><bdi>'.$num($value).'</bdi><span class="money-unit">ریال</span></span>');
    $dash = fn ($value) => filled($value) ? $value : '—';
    $isWarehouseMode = $printData['mode'] !== 'customer';
    $totalQuantity = $printData['items']->sum('quantity');
    $totalLineAmount = $printData['items']->sum('lineTotal');
@endphp
<div class="no-print">
    <a class="btn" href="{{ $printData['backUrl'] }}">بازگشت</a>
    <div>
        <a class="btn" href="?mode=customer">نسخه مشتری</a>
        <a class="btn" href="?mode=warehouse">نسخه انبار</a>
        <button class="btn btn-primary" onclick="window.print()">چاپ</button>
    </div>
</div>
<main class="invoice-page">
    <header class="invoice-header">
        <div class="header-brand">
        <div class="brand"><img src="{{ $printData['logo'] }}" alt="{{ $fa($printData['company']['name']) }}"><div><div class="brand-name">{{ $fa($printData['company']['name']) }}</div><div>{{ $fa($printData['company']['phone']) }}</div></div></div>
            @include('prints.partials.invoice-customer-header')
        </div>
        <div><h1 class="doc-title">{{ $fa($printData['title']) }}</h1><div class="doc-meta">
            <div>{{ $fa($printData['numberLabel']) }}: <strong>{{ $fa($printData['number']) }}</strong></div>
            @if($printData['customerNumber'])<div>شماره فاکتور مشتری: {{ $fa($printData['customerNumber']) }}</div>@endif
            <div>تاریخ سند: {{ $fa($fmtDate($printData['registeredAt'])) }}</div>
            @if($isWarehouseMode && $printData['issuedAt'] && $printData['issuedAt'] != $printData['registeredAt'])<div>تاریخ صدور فاکتور: {{ $fa($fmtDate($printData['issuedAt'])) }}</div>@endif
            @if($isWarehouseMode && $printData['status'])<div>وضعیت سند: {{ $fa($printData['status']) }}</div>@endif
            @if(filled($printData['seller']))<div>فروشنده: {{ $fa($printData['seller']) }}</div>@endif
        </div></div>
    </header>
    @if(filled($printData['shipping']['method']) || filled($printData['shipping']['description']))
    <section class="info-grid {{ $fa(!filled($printData['shipping']['method']) && !filled($printData['shipping']['description']) ? 'single-info' : '') }}">
        @if(filled($printData['shipping']['method']) || filled($printData['shipping']['description']))
        <div class="info-box"><div class="info-title">اطلاعات ارسال</div>
            <div class="info-row"><span class="label">روش ارسال:</span><span>{{ $fa($dash($printData['shipping']['method'])) }}</span></div>
            @if(filled($printData['shipping']['description']))<div class="info-row"><span class="label">توضیحات:</span><span>{{ $fa($printData['shipping']['description']) }}</span></div>@endif
        </div>
        @endif
    </section>
    @endif
    @include('prints.partials.customer-invoice-items')
    @unless($isWarehouseMode)
        <section class="summary-section">
            <div></div><table class="summary-table"><tbody>
                <tr><td>جمع قبل از تخفیف</td><td>{{ $money($printData['totals']['subtotal']) }}</td></tr>
                @if($printData['totals']['itemsDiscount'] > 0)<tr><td>جمع تخفیف کالاها</td><td>{{ $money($printData['totals']['itemsDiscount']) }}</td></tr>@endif
                <tr><td>تخفیف کلی فاکتور</td><td>{{ $money($printData['totals']['invoiceDiscount']) }}</td></tr>
                <tr><td>هزینه ارسال</td><td>{{ $money($printData['totals']['shipping']) }}</td></tr>
                @if(!is_null($printData['totals']['paid']))<tr><td>مبلغ پرداخت‌شده</td><td>{{ $money($printData['totals']['paid']) }}</td></tr>@endif
                @if(!is_null($printData['totals']['remaining']))<tr><td>مانده</td><td>{{ $money($printData['totals']['remaining']) }}</td></tr>@endif
                <tr class="final-row"><td>مبلغ نهایی</td><td>{{ $money($printData['totals']['total']) }}</td></tr>
            </tbody></table>
        </section>
        @if(($printData['documentType'] ?? null) === 'invoice')
            @if($printData['accountBalance'] ?? null)
                <section class="customer-balance-box">
                    <div class="info-title">مانده کل حساب مشتری</div>
                    <div class="customer-balance-details">
                        <strong>{{ $money($printData['accountBalance']['amount']) }}</strong>
                        <span class="customer-balance-status">{{ $fa($printData['accountBalance']['status']) }}</span>
                        <span class="customer-balance-time">تا زمان چاپ: {{ $fa($fmtDate($printData['accountBalance']['asOf'])) }}</span>
                    </div>
                    <div class="customer-balance-hint">این مبلغ مربوط به کل گردش حساب مشتری است و با مانده همین فاکتور تفاوت دارد.</div>
                </section>
            @endif
            @php($paymentDetails = $printData['customerPayment'] ?? [])
            @if(filled($printData['paymentWarning'] ?? null))
                <section class="customer-payment-box"><strong>{{ $fa($printData['paymentWarning']) }}</strong></section>
            @endif
            @if(collect($paymentDetails)->contains(fn ($value) => filled($value)))
                <section class="customer-payment-box">
                    <div class="info-title">مشخصات واریز</div>
                    <div class="customer-payment-grid">
                        @if(filled($paymentDetails['name'] ?? null))
                            <div><span class="payment-label">صاحب حساب:</span><strong>{{ $fa($paymentDetails['name']) }}</strong></div>
                        @endif
                        @if(filled($paymentDetails['national_id'] ?? null))
                            <div><span class="payment-label">شماره ملی:</span><strong class="payment-number" dir="ltr">{{ $fa($paymentDetails['national_id']) }}</strong></div>
                        @endif
                        @if(filled($paymentDetails['iban'] ?? null))
                            <div><span class="payment-label">شماره شبا:</span><strong class="payment-number" dir="ltr">{{ $fa($paymentDetails['iban']) }}</strong></div>
                        @endif
                        @if(filled($paymentDetails['account'] ?? null))
                            <div><span class="payment-label">شماره حساب:</span><strong class="payment-number" dir="ltr">{{ $fa($paymentDetails['account']) }}</strong></div>
                        @endif
                        @if(filled($paymentDetails['card'] ?? null))
                            <div><span class="payment-label">شماره کارت:</span><strong class="payment-number" dir="ltr">{{ $fa($paymentDetails['card']) }}</strong></div>
                        @endif
                    </div>
                </section>
            @endif
        @endif
        <section class="company-box"><div class="info-title">اطلاعات شرکت و پرداخت</div>
            <div><strong>{{ $fa($printData['company']['name']) }}</strong> | تلفن: {{ $fa($printData['company']['phone']) }}</div>
            <div>آدرس: {{ $fa($printData['company']['address']) }}</div>
            @if(filled($printData['company']['bank_account']))<div>شماره حساب شرکت: {{ $fa($printData['company']['bank_account']) }}</div>@endif
            @if(filled($printData['company']['sheba']))<div>شماره شبا شرکت: {{ $fa($printData['company']['sheba']) }}</div>@endif
        </section>
    @endunless
    <section class="signature-section"><div class="signature-box">امضا و مهر فروشنده</div><div class="signature-box">امضای مشتری / تحویل‌گیرنده</div></section>
</main>
