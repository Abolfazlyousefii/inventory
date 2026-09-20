@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $statusLabels = ['draft' => 'پیش‌نویس', 'confirmed' => 'تأیید‌شده', 'finalized' => 'نهایی‌شده'];
    $remaining = (int) $document->total_seller_commission - (int) $document->total_allocated;
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سند پورسانت اداری {{ $document->document_number }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Tahoma, Arial, sans-serif; font-size: 11px; color: #222; direction: rtl; line-height: 1.7; }
        .header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { font-size: 17px; }
        .header .sub { font-size: 11px; color: #666; }
        .info { display: flex; flex-wrap: wrap; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; }
        .info div { flex: 1 1 32%; padding: 2px 4px; }
        .info .lbl { font-size: 9px; color: #888; display: block; }
        .info .val { font-weight: bold; }
        .cards { display: flex; gap: 6px; margin-bottom: 12px; }
        .cards div { flex: 1; border: 1px solid #ddd; border-radius: 4px; padding: 6px; text-align: center; }
        .cards .lbl { font-size: 8px; color: #888; display: block; }
        .cards .val { font-weight: bold; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #ddd; padding: 4px 6px; text-align: right; }
        th { background: #f3f3f3; font-size: 10px; }
        .dept-row td { font-weight: bold; background: #fafafa; }
        .member-table { margin: 0; }
        .member-table th, .member-table td { border: 0; border-bottom: 1px dotted #e3e3e3; font-size: 10px; padding: 2px 6px; }
        .notes { border: 1px solid #ddd; border-radius: 4px; padding: 6px 8px; margin-bottom: 14px; }
        .notes .lbl { font-size: 9px; color: #888; display: block; }
        .signatures { display: flex; gap: 10px; margin-top: 24px; }
        .signatures div { flex: 1; border-top: 1px solid #999; padding-top: 6px; text-align: center; font-size: 10px; }
        .signatures .name { display: block; margin-top: 18px; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">

<div class="header">
    <h1>سند پورسانت اداری</h1>
    <span class="sub">شماره سند: {{ $document->document_number }} — وضعیت: {{ $statusLabels[$document->status] ?? $document->status }}</span>
</div>

<div class="info">
    <div><span class="lbl">بازه سند</span><span class="val">{{ JalaliDate::date($document->period_from) }} — {{ JalaliDate::date($document->period_to) }}</span></div>
    <div><span class="lbl">تاریخ ایجاد</span><span class="val">{{ JalaliDate::date($document->created_at) }}</span></div>
    <div><span class="lbl">ایجادکننده</span><span class="val">{{ $document->creator?->name ?? '—' }}</span></div>
</div>

<div class="cards">
    <div><span class="lbl">کل پورسانت فروشندگان</span><span class="val">{{ Currency::formatRial($document->total_seller_commission) }}</span></div>
    <div><span class="lbl">مبلغ کل تخصیص</span><span class="val">{{ Currency::formatRial($document->total_allocated) }}</span></div>
    <div><span class="lbl">باقی‌مانده تخصیص‌نشده</span><span class="val">{{ Currency::formatRial($remaining) }}</span></div>
</div>

<table>
    <thead>
        <tr><th style="width:40%">واحد</th><th style="width:15%">درصد</th><th style="width:25%">مبلغ تخصیص</th><th style="width:20%">تعداد اعضا</th></tr>
    </thead>
    <tbody>
        @forelse($document->allocations as $allocation)
            <tr class="dept-row">
                <td>{{ $allocation->department?->name ?? '—' }}</td>
                <td>{{ rtrim(rtrim($allocation->percentage, '0'), '.') }}٪</td>
                <td>{{ Currency::formatRial($allocation->allocated_amount) }}</td>
                <td>{{ $allocation->memberShares->count() }}</td>
            </tr>
            @if($allocation->memberShares->isNotEmpty())
                <tr>
                    <td colspan="4" style="padding:0">
                        <table class="member-table">
                            <thead><tr><th style="width:30%">عضو</th><th style="width:20%">سمت</th><th style="width:25%">مبلغ سهم</th><th style="width:25%">یادداشت</th></tr></thead>
                            <tbody>
                                @foreach($allocation->memberShares as $share)
                                    <tr>
                                        <td>{{ $share->member?->name ?? '—' }}</td>
                                        <td>{{ $share->member?->role ?: '—' }}</td>
                                        <td>{{ Currency::formatRial($share->share_amount) }}</td>
                                        <td>{{ $share->notes ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
            @endif
        @empty
            <tr><td colspan="4" style="text-align:center">این سند تخصیصی ندارد.</td></tr>
        @endforelse
    </tbody>
</table>

@if($document->notes)
    <div class="notes"><span class="lbl">یادداشت سند</span>{{ $document->notes }}</div>
@endif

<div class="signatures">
    <div>ایجادکننده<span class="name">{{ $document->creator?->name ?? '—' }}</span></div>
    <div>تأییدکننده<span class="name">{{ $document->confirmer?->name ?? '—' }}</span></div>
    <div>نهایی‌کننده<span class="name">{{ $document->finalizer?->name ?? '—' }}</span></div>
</div>

</body>
</html>
