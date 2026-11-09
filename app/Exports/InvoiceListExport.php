<?php

namespace App\Exports;

use App\Support\{InvoiceListReportRow,JalaliDate};
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\{FromQuery,WithMapping,WithHeadings,WithEvents,WithColumnFormatting,WithColumnWidths,WithCustomValueBinder,WithStrictNullComparison};
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\{Cell,DataType,DefaultValueBinder};

class InvoiceListExport extends DefaultValueBinder implements FromQuery,WithMapping,WithHeadings,WithEvents,WithColumnFormatting,WithColumnWidths,WithCustomValueBinder,WithStrictNullComparison
{
    private int $row=0;
    public function __construct(private Builder $invoices,private array $summary,private string $filters,private array $statusLabels) {}
    public function query(): Builder {return clone $this->invoices;}
    public function headings(): array {return [
        ['لیست فاکتورهای فروش'],[$this->filters],['تهیه: '.JalaliDate::dateTime(now()).' | مبالغ به ریال'],
        ['ردیف','شماره فاکتور','تاریخ ثبت','مشتری','فروشنده','وضعیت','مبلغ فاکتور (ریال)','پرداخت‌شده (ریال)','مانده (ریال)'],
    ];}
    public function map($invoice): array {return InvoiceListReportRow::values($invoice,$this->statusLabels,++$this->row);}
    public function columnFormats(): array {return ['G'=>'#,##0','H'=>'#,##0','I'=>'#,##0'];}
    public function columnWidths(): array {return ['A'=>8,'B'=>17,'C'=>22,'D'=>30,'E'=>23,'F'=>26,'G'=>24,'H'=>24,'I'=>24];}
    public function bindValue(Cell $cell,$value): bool {
        if(is_string($value)){$cell->setValueExplicit($value,DataType::TYPE_STRING);return true;}
        return parent::bindValue($cell,$value);
    }
    public function registerEvents(): array {return [AfterSheet::class=>function(AfterSheet $event){
        $sheet=$event->sheet->getDelegate();$last=$sheet->getHighestRow();$total=$last+1;
        $sheet->setRightToLeft(true);$sheet->freezePane('A5');
        foreach([1,2,3] as $row)$sheet->mergeCells('A'.$row.':I'.$row);
        $sheet->getStyle('A1:I'.$total)->getFont()->setName('Tahoma')->setSize(11);
        $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A2:I'.$total)->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(34);$sheet->getRowDimension(2)->setRowHeight(42);$sheet->getRowDimension(4)->setRowHeight(30);
        $sheet->getStyle('A4:I4')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'234F68']]]);
        if($last>=5)$sheet->setAutoFilter('A4:I'.$last);
        $sheet->mergeCells('A'.$total.':F'.$total);
        $sheet->setCellValueExplicit('A'.$total,'جمع '.$this->summary['invoice_count'].' فاکتور',DataType::TYPE_STRING);
        foreach(['G'=>'total_sales','H'=>'paid_amount','I'=>'remaining_amount'] as $col=>$key)$sheet->setCellValue($col.$total,$this->summary[$key]);
        $sheet->getStyle('G5:I'.$total)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A'.$total.':I'.$total)->getFont()->setBold(true);
        $sheet->getStyle('A'.$total.':I'.$total)->getFill()->setFillType('solid')->getStartColor()->setRGB('EAF2F7');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(4,4);
    }];}
}
