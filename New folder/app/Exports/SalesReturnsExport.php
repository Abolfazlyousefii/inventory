<?php

namespace App\Exports;

use App\Services\SalesReturnReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\{FromCollection, ShouldAutoSize, WithColumnFormatting, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithStyles};
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesReturnsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting, WithColumnWidths, WithEvents
{
    private int $row = 0;

    public function __construct(private array $filters, private SalesReturnReportService $reportService) {}

    public function collection(): Collection
    {
        return $this->reportService->getExcelRows($this->filters);
    }

    public function headings(): array
    {
        return ['ردیف','شماره حواله یا شماره سند برگشت','نام مشتری','تاریخ برگشت','نوع برگشت','مبلغ سالم','مبلغ مرجوعی','مبلغ کل برگشت از فروش'];
    }

    public function map($row): array
    {
        return [++$this->row, $row['document_number'], $row['customer_name'], $row['returned_at_display'], $row['return_type'], (int) $row['healthy_amount'], (int) $row['damaged_amount'], (int) $row['total_amount']];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->setRightToLeft(true);
        $sheet->freezePane('A2');
        $sheet->getStyle('A:H')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:H1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A:H')->getFont()->setName('Vazirmatn');
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'color' => ['rgb' => 'EAF2F8']]]];
    }

    public function columnFormats(): array
    {
        return ['F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function columnWidths(): array
    {
        return ['A'=>8,'B'=>28,'C'=>24,'D'=>18,'E'=>14,'F'=>18,'G'=>18,'H'=>24];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $highestRow = $event->sheet->getHighestRow();
            $event->sheet->getStyle("A1:H{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
            for ($row = 2; $row <= $highestRow; $row++) {
                foreach (['F','G','H'] as $column) {
                    $value = $event->sheet->getCell("{$column}{$row}")->getValue();
                    $event->sheet->setCellValueExplicit("{$column}{$row}", (int) $value, DataType::TYPE_NUMERIC);
                }
            }
        }];
    }
}
