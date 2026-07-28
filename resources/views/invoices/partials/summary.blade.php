<div class="invoice-summary__item"><span>تعداد فاکتور</span><strong>{{ number_format($summary['invoice_count']) }}</strong></div>
<div class="invoice-summary__item"><span>فروش کل</span><strong>{{ number_format($summary['total_sales']) }} ریال</strong></div>
<div class="invoice-summary__item"><span>دریافتی</span><strong class="text-success">{{ number_format($summary['paid_amount']) }} ریال</strong></div>
<div class="invoice-summary__item"><span>مانده</span><strong class="text-danger">{{ number_format($summary['remaining_amount']) }} ریال</strong></div>
