        <div class="header-customer"><div class="info-title">مشخصات مشتری</div>
            <div class="info-row"><span class="label">نام مشتری:</span><strong>{{ $fa($dash($printData['customer']['name'])) }}</strong></div>
            <div class="info-row"><span class="label">شماره تماس:</span><span>{{ $fa($dash($printData['customer']['mobile'])) }}</span></div>
            @if(filled($printData['customer']['code']))<div class="info-row"><span class="label">کد مشتری:</span><span>{{ $fa($printData['customer']['code']) }}</span></div>@endif
            @if(filled($printData['customer']['address']))<div class="info-row"><span class="label">آدرس:</span><span>{{ $fa($printData['customer']['address']) }}</span></div>@endif
        </div>
