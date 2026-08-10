<?php

namespace App\Exports;

use App\Models\Purchase;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PurchasesExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping
{
    private int $row = 0;

    public function __construct(private readonly array $filters = [])
    {
    }

    public function query(): Builder
    {
        return Purchase::query()
            ->with('supplier:id,name')
            ->when(($this->filters['supplier_id'] ?? 0) > 0, fn ($q) => $q->where('supplier_id', (int) $this->filters['supplier_id']))
            ->when(($this->filters['date_from'] ?? '') !== '', fn ($q) => $q->where('purchased_at', '>=', Carbon::parse($this->filters['date_from'])->startOfDay()))
            ->when(($this->filters['date_to'] ?? '') !== '', fn ($q) => $q->where('purchased_at', '<=', Carbon::parse($this->filters['date_to'])->endOfDay()))
            ->orderBy('purchased_at')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return ['ردیف', 'تاریخ فاکتور خرید', 'نام تأمین‌کننده', 'شماره فاکتور خرید', 'مبلغ کل فاکتور'];
    }

    public function map($purchase): array
    {
        return [
            ++$this->row,
            JalaliDate::dateTime($purchase->purchased_at),
            $purchase->supplier?->name ?: '—',
            (string) $purchase->id,
            (int) ($purchase->total_amount ?? 0),
        ];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->setRightToLeft(true);
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('A1:E1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
        }];
    }
}
