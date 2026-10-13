<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, ShouldAutoSize, WithCustomValueBinder};
use PhpOffice\PhpSpreadsheet\Cell\{Cell, DataType, DefaultValueBinder};

class TopProductsExport extends DefaultValueBinder implements FromArray, WithHeadings, ShouldAutoSize, WithCustomValueBinder
{
    public function __construct(private array $rows, private array $filters) {}
    public function headings(): array { return ['از تاریخ ارسال','تا تاریخ ارسال','کالا','تنوع','تعداد فروش','مبلغ فروش پس از تخفیف (ریال)','سهم از معیار انتخابی (%)','موجودی آزاد فعلی مرکزی','دسته منتخب','فروشنده منتخب','معیار رتبه‌بندی','فاکتورهای بدون تاریخ ارسال (کل سوابق، محاسبه نشده)']; }
    public function array(): array
    {
        return array_map(fn($r)=>[$this->filters['date_from'],$this->filters['date_to'],$r['name'],$r['variant_name'],$r['quantity'],$r['revenue'],$r['share'],$r['stock'],$this->filters['category_name']??'همه',$this->filters['seller_name']??'همه',$this->filters['metric']==='quantity'?'تعداد':'مبلغ',$this->filters['undated_invoices']??0],$this->rows);
    }
    public function bindValue(Cell $cell, $value): bool
    {
        // Customer-entered names remain text, never spreadsheet formulas.
        if(is_string($value)){ $cell->setValueExplicit($value,DataType::TYPE_STRING); return true; }
        return parent::bindValue($cell,$value);
    }
}
