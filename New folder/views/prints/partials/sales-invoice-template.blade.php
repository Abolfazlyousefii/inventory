@php
    $fmtDate = function ($value, string $format = 'Y/m/d H:i') {
        if (!$value) return '—';
        try { return \Morilog\Jalali\Jalalian::fromDateTime($value)->format($format); } catch (\Throwable $e) { return (string) $value; }
    };
    $money = fn ($value) => \App\Support\Currency::formatRial((int) $value);
    $dash = fn ($value) => filled($value) ? $value : '—';
    $isWarehouseMode = $printData['mode'] !== 'customer';
    $itemColspan = $isWarehouseMode ? 6 : 8;
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
        <div class="brand"><img src="{{ $printData['logo'] }}" alt="{{ $printData['company']['name'] }}"><div><div class="brand-name">{{ $printData['company']['name'] }}</div><div>{{ $printData['company']['phone'] }}</div></div></div>
        <div><h1 class="doc-title">{{ $printData['title'] }}</h1><div class="doc-meta">
            <div>{{ $printData['numberLabel'] }}: <strong>{{ $printData['number'] }}</strong></div>
            @if($printData['customerNumber'])<div>شماره فاکتور مشتری: {{ $printData['customerNumber'] }}</div>@endif
            <div>تاریخ ثبت پیش‌فاکتور: {{ $fmtDate($printData['registeredAt']) }}</div>
            @if($printData['issuedAt'])<div>تاریخ صدور فاکتور: {{ $fmtDate($printData['issuedAt']) }}</div>@endif
            @if($printData['status'])<div>وضعیت سند: {{ $printData['status'] }}</div>@endif
            @if(filled($printData['seller']))<div>فروشنده: {{ $printData['seller'] }}</div>@endif
        </div></div>
    </header>
    <section class="info-grid">
        <div class="info-box"><div class="info-title">مشخصات مشتری</div>
            <div class="info-row"><span class="label">نام مشتری:</span><strong>{{ $dash($printData['customer']['name']) }}</strong></div>
            <div class="info-row"><span class="label">شماره تماس:</span><span>{{ $dash($printData['customer']['mobile']) }}</span></div>
            <div class="info-row"><span class="label">کد مشتری:</span><span>{{ $dash($printData['customer']['code']) }}</span></div>
            <div class="info-row"><span class="label">آدرس:</span><span>{{ $dash($printData['customer']['address']) }}</span></div>
        </div>
        <div class="info-box"><div class="info-title">اطلاعات ارسال</div>
            <div class="info-row"><span class="label">روش ارسال:</span><span>{{ $dash($printData['shipping']['method']) }}</span></div>
            <div class="info-row"><span class="label">هزینه ارسال:</span><span>{{ $money($printData['shipping']['cost']) }}</span></div>
            @if(filled($printData['shipping']['description']))<div class="info-row"><span class="label">توضیحات:</span><span>{{ $printData['shipping']['description'] }}</span></div>@endif
        </div>
    </section>
    <table class="items-table">
        @if($isWarehouseMode)
            <thead><tr><th class="col-index">ردیف</th><th class="col-desc">شرح کالا</th><th class="col-code">کد انبار</th><th class="col-map">نقشه انبار</th><th class="col-qty">تعداد</th><th class="col-check">کنترل انبار</th></tr></thead>
        @else
            <thead><tr><th class="col-index">ردیف</th><th class="col-desc">شرح کالا</th><th class="col-code">کد انبار</th><th class="col-qty">تعداد</th><th class="col-price">قیمت واحد</th><th class="col-price">تخفیف ردیف</th><th class="col-price">قیمت خالص</th><th class="col-total">مبلغ کل</th></tr></thead>
        @endif
        <tbody>
        @forelse($printData['items'] as $item)
            @if($isWarehouseMode)
                <tr><td class="col-index">{{ $loop->iteration }}</td><td class="col-desc">{{ $item['description'] }}</td><td class="col-code">{{ $item['inventoryCode'] }}</td><td class="col-map">{{ $item['warehouseMap'] }}</td><td class="col-qty">{{ number_format($item['quantity']) }}</td><td class="col-check"></td></tr>
            @else
                <tr><td class="col-index">{{ $loop->iteration }}</td><td class="col-desc">{{ $item['description'] }}</td><td class="col-code">{{ $item['inventoryCode'] }}</td><td class="col-qty">{{ number_format($item['quantity']) }}</td><td class="col-price">{{ number_format($item['unitPrice']) }}</td><td class="col-price">{{ number_format($item['lineDiscount'] ?? 0) }}</td><td class="col-price">{{ number_format($item['netUnitPrice'] ?? $item['unitPrice']) }}</td><td class="col-total">{{ number_format($item['lineTotal']) }}</td></tr>
            @endif
        @empty
            <tr><td colspan="{{ $itemColspan }}" style="text-align:center">آیتمی ثبت نشده است.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr class="items-total-row">
                <td class="col-index"></td>
                <td class="col-desc">جمع کل</td>
                <td class="col-code"></td>
                @if($isWarehouseMode)
                    <td class="col-map"></td>
                    <td class="col-qty">{{ number_format($totalQuantity) }}</td>
                    <td class="col-check"></td>
                @else
                    <td class="col-qty">{{ number_format($totalQuantity) }}</td>
                    <td class="col-price"></td>
                    <td class="col-price"></td>
                    <td class="col-price"></td>
                    <td class="col-total">{{ number_format($totalLineAmount) }}</td>
                @endif
            </tr>
        </tfoot>
    </table>
    @unless($isWarehouseMode)
        <section class="summary-section">
            <div></div><table class="summary-table"><tbody>
                <tr><td>جمع کالاها</td><td>{{ $money($printData['totals']['itemsNetSubtotal']) }}</td></tr>
                <tr><td>تخفیف کلی فاکتور</td><td>{{ $money($printData['totals']['invoiceDiscount']) }}</td></tr>
                <tr><td>هزینه ارسال</td><td>{{ $money($printData['totals']['shipping']) }}</td></tr>
                @if(!is_null($printData['totals']['paid']))<tr><td>مبلغ پرداخت‌شده</td><td>{{ $money($printData['totals']['paid']) }}</td></tr>@endif
                @if(!is_null($printData['totals']['remaining']))<tr><td>مانده</td><td>{{ $money($printData['totals']['remaining']) }}</td></tr>@endif
                <tr class="final-row"><td>مبلغ نهایی</td><td>{{ $money($printData['totals']['total']) }}</td></tr>
            </tbody></table>
        </section>
        <section class="company-box"><div class="info-title">اطلاعات شرکت و پرداخت</div>
            <div><strong>{{ $printData['company']['name'] }}</strong> | تلفن: {{ $printData['company']['phone'] }}</div>
            <div>آدرس: {{ $printData['company']['address'] }}</div>
            @if(filled($printData['company']['bank_account']))<div>شماره حساب شرکت: {{ $printData['company']['bank_account'] }}</div>@endif
            @if(filled($printData['company']['sheba']))<div>شماره شبا شرکت: {{ $printData['company']['sheba'] }}</div>@endif
        </section>
    @endunless
    <section class="signature-section"><div class="signature-box">امضا و مهر فروشنده</div><div class="signature-box">امضای مشتری / تحویل‌گیرنده</div></section>
</main>
