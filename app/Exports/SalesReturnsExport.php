<?php

namespace App\Exports;

use App\Models\Category;
use App\Models\WarehouseTransfer;
use App\Support\JalaliDate;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SalesReturnsExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping
{
    private int $row = 0;

    public function __construct(private readonly array $filters = [])
    {
    }

    public function query(): Builder
    {
        return self::baseQuery($this->filters)
            ->orderBy('transferred_at')
            ->orderBy('id');
    }

    public static function baseQuery(array $filters): Builder
    {
        return WarehouseTransfer::query()
            ->with(['customer:id,first_name,last_name,mobile,crm_customer_id', 'toWarehouse:id,name,type', 'fromWarehouse:id,name,type', 'items:id,warehouse_transfer_id,line_total,return_kind,destination_warehouse_id', 'items.destinationWarehouse:id,name,type'])
            ->withCount('items as returned_items_count')
            ->withSum('items as returned_items_total_amount', 'line_total')
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->when(($filters['customer_id'] ?? 0) > 0, fn ($q) => $q->where('customer_id', (int) $filters['customer_id']))
            ->when(($filters['customer_name'] ?? '') !== '', function ($q) use ($filters) {
                $name = trim((string) $filters['customer_name']);
                $q->whereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$name}%")->orWhere('last_name', 'like', "%{$name}%"));
            })
            ->when(($filters['document_number'] ?? '') !== '', fn ($q) => $q->where(function ($n) use ($filters) {
                $term = '%' . trim((string) $filters['document_number']) . '%';
                $n->where('reference', 'like', $term)->orWhere('external_invoice_number', 'like', $term);
            }))
            ->when(($filters['return_reason'] ?? '') !== '' && isset(WarehouseTransfer::returnReasonOptions()[$filters['return_reason']]), fn ($q) => $q->where('return_reason', $filters['return_reason']))
            ->when(($filters['date_from'] ?? '') !== '', fn ($q) => $q->whereDate('transferred_at', '>=', $filters['date_from']))
            ->when(($filters['date_to'] ?? '') !== '', fn ($q) => $q->whereDate('transferred_at', '<=', $filters['date_to']))
            ->when(($filters['warehouse_id'] ?? 0) > 0, fn ($q) => $q->where(function ($w) use ($filters) { $id = (int) $filters['warehouse_id']; $w->where('to_warehouse_id', $id)->orWhereHas('items', fn ($i) => $i->where('destination_warehouse_id', $id)); }))
            ->when(($filters['return_kind'] ?? '') !== '', function ($q) use ($filters) {
                $kind = $filters['return_kind'];
                if ($kind === 'mixed') {
                    $q->whereHas('items', fn ($i) => $i->where('return_kind', 'healthy'))->whereHas('items', fn ($i) => $i->where('return_kind', 'damaged'));
                } elseif (in_array($kind, ['healthy', 'damaged'], true)) {
                    $other = $kind === 'healthy' ? 'damaged' : 'healthy';
                    $q->whereHas('items', fn ($i) => $i->where(function ($r) use ($kind) { $r->where('return_kind', $kind)->orWhereNull('return_kind'); }))
                      ->whereDoesntHave('items', fn ($i) => $i->where('return_kind', $other));
                }
            })
            ->when(($filters['product_id'] ?? 0) > 0, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_id', (int) $filters['product_id'])))
            ->when(($filters['variant_id'] ?? 0) > 0, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_variant_id', (int) $filters['variant_id'])))
            ->when(($filters['subcategory_id'] ?? 0) > 0, fn ($q) => $q->whereHas('items.product', fn ($p) => $p->where('category_id', (int) $filters['subcategory_id'])))
            ->when(($filters['category_id'] ?? 0) > 0 && ($filters['subcategory_id'] ?? 0) <= 0, fn ($q) => $q->whereHas('items.product', fn ($p) => $p->whereIn('category_id', Category::selfAndDescendantIds((int) $filters['category_id']))));
    }

    public function headings(): array
    {
        return ['ردیف', 'شماره حواله یا شماره سند برگشت', 'نام مشتری', 'تاریخ برگشت', 'نوع برگشت', 'مبلغ سالم', 'مبلغ مرجوعی', 'مبلغ کل برگشت از فروش'];
    }

    public function map($transfer): array
    {
        return [++$this->row, self::documentNumber($transfer), self::customerName($transfer), JalaliDate::dateTime($transfer->transferred_at), self::returnKindLabel($transfer), self::healthyAmount($transfer), self::damagedAmount($transfer), self::totalAmount($transfer)];
    }

    public static function totalAmount(WarehouseTransfer $transfer): int
    {
        return (int) ($transfer->returned_items_total_amount ?? $transfer->total_amount ?? $transfer->items?->sum('line_total') ?? 0);
    }

    public static function healthyAmount(WarehouseTransfer $transfer): int
    {
        return (int) $transfer->items->filter(fn ($item) => $item->effectiveReturnKind() === 'healthy')->sum('line_total');
    }

    public static function damagedAmount(WarehouseTransfer $transfer): int
    {
        return (int) $transfer->items->filter(fn ($item) => $item->effectiveReturnKind() === 'damaged')->sum('line_total');
    }

    public static function returnKindLabel(WarehouseTransfer $transfer): string
    {
        return $transfer->relationLoaded('items') ? $transfer->returnKindLabel() : 'نامشخص';
    }

    public static function customerName(WarehouseTransfer $transfer): string
    {
        $name = trim(($transfer->customer?->first_name ?? '') . ' ' . ($transfer->customer?->last_name ?? ''));
        return $name !== '' ? $name : ($transfer->beneficiary_name ?: '—');
    }

    public static function documentNumber(WarehouseTransfer $transfer): string
    {
        return $transfer->reference ?: ($transfer->external_invoice_number ?: ('TR-' . $transfer->id));
    }

    public static function destinationWarehouseLabel(WarehouseTransfer $transfer): string
    {
        return $transfer->toWarehouse?->name ?: 'نامشخص';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->setRightToLeft(true);
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A1:H1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
        }];
    }
}
