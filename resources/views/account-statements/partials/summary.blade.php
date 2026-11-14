<section class="asd-summary" aria-label="خلاصه حساب">
    <div><span>ماندهٔ اولیه</span><strong>{{ $rial($openingBalance) }}</strong><small>{{ $balanceText($openingBalance) }}</small></div>
    <div class="asd-summary-debt"><span>جمع بدهکار</span><strong>{{ $rial($totalDebit) }}</strong><small>کل گردش مؤثر</small></div>
    <div class="asd-summary-credit"><span>جمع بستانکار</span><strong>{{ $rial($totalCredit) }}</strong><small>کل گردش مؤثر</small></div>
    <div class="asd-current {{ $netBalance > 0 ? 'asd-debt' : ($netBalance < 0 ? 'asd-credit' : '') }}"><span>ماندهٔ فعلی حساب</span><strong>{{ $rial($netBalance) }}</strong><small>{{ $balanceText($netBalance) }}</small></div>
</section>
