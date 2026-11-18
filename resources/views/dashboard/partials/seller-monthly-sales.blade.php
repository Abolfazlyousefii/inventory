@php
    $persianDigits = static fn (string $value): string => strtr($value, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
@endphp
<section class="seller-monthly-sales" dir="rtl" aria-label="خلاصه فروش ماه جاری">
    <div class="seller-monthly-sales__heading">
        <strong>عملکرد فروش من</strong>
        <span>{{ $sellerMonthSummary['month_label'] }}</span>
    </div>
    <div class="seller-monthly-sales__metrics">
        <div class="seller-monthly-sales__metric">
            <span>فروش ماه جاری</span>
            <div class="seller-monthly-sales__value">
                <bdi>{{ $persianDigits(number_format($sellerMonthSummary['sales_toman'])) }}</bdi>
                <small>تومان</small>
            </div>
        </div>
        <div class="seller-monthly-sales__metric">
            <span>باقی‌مانده تا پایان ماه</span>
            <div class="seller-monthly-sales__value">
                <bdi>{{ $persianDigits((string) $sellerMonthSummary['days_remaining']) }}</bdi>
                <small>روز</small>
            </div>
        </div>
    </div>
</section>
