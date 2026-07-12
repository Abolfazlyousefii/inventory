<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><title>چاپ سند برگشت از فروش</title>
<style>
@page{size:A4 portrait;margin:14mm}body{font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;color:#111827;font-size:12px}.actions{margin-bottom:12px}.btn{border:1px solid #d1d5db;padding:6px 10px;background:#f9fafb;text-decoration:none;color:#111827;border-radius:4px}h1{font-size:22px;margin:0 0 14px}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px}.box{border:1px solid #d1d5db;padding:8px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #d1d5db;padding:7px 6px}th{background:#eef5fb}.signatures{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:30px}.sig{border-top:1px solid #111827;text-align:center;padding-top:8px}@media print{.actions{display:none}}
</style></head>
<body>
<div class="actions"><button class="btn" onclick="window.print()">چاپ</button> <a class="btn" href="{{ route('vouchers.return-from-sale.show',$document) }}">بازگشت</a></div>
<h1>سند برگشت از فروش</h1>
@php($destinations = $document->items->pluck('destinationWarehouse.name')->filter()->unique())
<div class="grid">
 <div class="box">شماره سند یا حواله: {{ $document->document_number }}</div><div class="box">تاریخ: {{ $document->created_at ? Morilog\Jalali\Jalalian::fromDateTime($document->created_at)->format('Y/m/d H:i') : '—' }}</div>
 <div class="box">مشتری: {{ $document->customer?->display_name ?: '—' }}</div><div class="box">فاکتور مرجع: {{ $document->invoice?->uuid ?: ($document->external_invoice_number ?: '—') }}</div>
 <div class="box">انبار مقصد: {{ $destinations->count() > 1 ? 'چند انبار' : ($destinations->first() ?: '—') }}</div><div class="box">علت برگشت: {{ App\Models\SalesReturnDocument::returnReasonLabels()[$document->return_reason] ?? ($document->return_reason ?: '—') }}</div>
</div>
<table><thead><tr><th>ردیف</th><th>کالا</th><th>تنوع</th><th>تعداد</th><th>مبلغ واحد</th><th>مبلغ کل</th></tr></thead><tbody>
@foreach($document->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product_name_snapshot ?: $item->product?->name ?: '—' }}</td><td>{{ $item->variant_name_snapshot ?: $item->variant?->variant_name ?: '—' }}</td><td>{{ number_format($item->return_quantity) }}</td><td>{{ number_format($item->refund_unit_price) }}</td><td>{{ number_format($item->refund_amount) }}</td></tr>@endforeach
</tbody><tfoot><tr><th colspan="5">جمع مبلغ</th><th>{{ number_format($document->total_refund_amount) }}</th></tr></tfoot></table>
<div class="box" style="margin-top:12px">توضیحات: {{ $document->description ?: '—' }}</div>
<div class="signatures"><div class="sig">امضای فروش</div><div class="sig">امضای مالی</div><div class="sig">امضای انبار</div><div class="sig">امضای مشتری</div></div>
</body></html>
