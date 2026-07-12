<?php

namespace App\Exports;

use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Services\SalesReturnQueryService;
use Maatwebsite\Excel\Concerns\{FromQuery, ShouldAutoSize, WithChunkReading, WithColumnFormatting, WithHeadings, WithMapping, WithStyles};
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Morilog\Jalali\Jalalian;

class SalesReturnsExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    private int $row = 0;
    public function __construct(private array $filters, private SalesReturnQueryService $queryService) {}
    public function query() { return $this->queryService->buildItemQuery($this->filters); }
    public function headings(): array { return ['ردیف','شماره سند برگشت','وضعیت سند','نوع برگشت','مشتری','موبایل مشتری','شماره فاکتور داخلی','شماره فاکتور سازه‌حساب','شماره مرجع','تاریخ ثبت','تاریخ Apply','کالا','تنوع','SKU','Barcode','تعداد','وضعیت کالا','انبار مقصد','مبلغ واحد بستانکاری','مبلغ کل ردیف','مبلغ کل سند','علت برگشت','ثبت‌کننده','نهایی‌کننده']; }
    public function map($item): array { $doc=$item->document; return [++$this->row, $doc?->document_number, SalesReturnDocument::statusLabels()[$doc?->status]??$doc?->status, SalesReturnDocument::sourceTypeLabels()[$doc?->source_type]??$doc?->source_type, $doc?->customer?->display_name, $doc?->customer?->mobile, $doc?->invoice?->uuid, $doc?->external_invoice_number, $doc?->reference_number, $doc?->created_at ? Jalalian::fromDateTime($doc->created_at)->format('Y/m/d H:i') : null, $doc?->applied_at ? Jalalian::fromDateTime($doc->applied_at)->format('Y/m/d H:i') : null, $item->product_name_snapshot ?: $item->product?->name, $item->variant_name_snapshot ?: $item->variant?->variant_name, $item->sku_snapshot ?: $item->variant?->variant_code, $item->barcode_snapshot ?: $item->variant?->variant_code, (int)$item->return_quantity, SalesReturnDocumentItem::conditionLabels()[$item->item_condition]??$item->item_condition, $item->destinationWarehouse?->name, (int)$item->refund_unit_price, (int)$item->refund_amount, (int)$doc?->total_refund_amount, $doc?->return_reason, $doc?->creator?->name, $doc?->applier?->name]; }
    public function chunkSize(): int { return 500; }
    public function styles(Worksheet $sheet): array { $sheet->setRightToLeft(true); $sheet->freezePane('A2'); return [1=>['font'=>['bold'=>true]]]; }
    public function columnFormats(): array { return ['S'=>NumberFormat::FORMAT_NUMBER, 'T'=>NumberFormat::FORMAT_NUMBER, 'U'=>NumberFormat::FORMAT_NUMBER]; }
}
