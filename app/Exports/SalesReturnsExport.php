<?php

namespace App\Exports;

use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferItem;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use App\Support\JalaliDate;

class SalesReturnsExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping
{
    public function __construct(private readonly array $filters = [])
    {
    }

    public function query(): Builder
    {
        return WarehouseTransferItem::query()
            ->select('warehouse_transfer_items.*')
            ->join('warehouse_transfers', 'warehouse_transfers.id', '=', 'warehouse_transfer_items.warehouse_transfer_id')
            ->with([
                'transfer' => fn ($query) => $query
                    ->with(['customer', 'user'])
                    ->withCount('items as returned_items_count')
                    ->withSum('items as returned_items_total_amount', 'line_total'),
                'product.category.parent',
                'variant.modelList',
                'variant.color',
            ])
            ->where('warehouse_transfers.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->when(($this->filters['product_id'] ?? 0) > 0, fn ($q) => $q->where('warehouse_transfer_items.product_id', (int) $this->filters['product_id']))
            ->when(($this->filters['variant_id'] ?? 0) > 0, fn ($q) => $q->where('warehouse_transfer_items.product_variant_id', (int) $this->filters['variant_id']))
            ->when(($this->filters['customer_id'] ?? 0) > 0, fn ($q) => $q->where('warehouse_transfers.customer_id', (int) $this->filters['customer_id']))
            ->when(($this->filters['return_reason'] ?? '') !== '' && isset(WarehouseTransfer::returnReasonOptions()[$this->filters['return_reason']]), fn ($q) => $q->where('warehouse_transfers.return_reason', $this->filters['return_reason']))
            ->when(($this->filters['subcategory_id'] ?? 0) > 0, fn ($q) => $q->whereHas('product', fn ($productQuery) => $productQuery->where('category_id', (int) $this->filters['subcategory_id'])))
            ->when(($this->filters['category_id'] ?? 0) > 0 && ($this->filters['subcategory_id'] ?? 0) <= 0, fn ($q) => $q->whereHas('product', fn ($productQuery) => $productQuery->whereIn('category_id', \App\Models\Category::selfAndDescendantIds((int) $this->filters['category_id']))))
            ->when(($this->filters['date_from'] ?? '') !== '', fn ($q) => $q->whereDate('warehouse_transfers.transferred_at', '>=', $this->filters['date_from']))
            ->when(($this->filters['date_to'] ?? '') !== '', fn ($q) => $q->whereDate('warehouse_transfers.transferred_at', '<=', $this->filters['date_to']))
            ->orderBy('warehouse_transfers.transferred_at')
            ->orderBy('warehouse_transfers.id')
            ->orderBy('warehouse_transfer_items.id');
    }

    public function headings(): array
    {
        return [
            'شماره سند برگشت از فروش', 'تاریخ سند', 'نام مشتری', 'کد مشتری', 'کد کالا', 'نام کالا', 'دسته‌بندی کالا',
            'زیر‌دسته‌بندی کالا', 'تنوع کالا / Variant', 'تعداد آیتم برگشتی سند', 'مبلغ کل برگشتی سند', 'تعداد برگشتی ردیف', 'واحد کالا', 'مبلغ برگشتی ردیف', 'علت برگشت', 'توضیحات', 'کاربر ثبت‌کننده', 'تاریخ ثبت', 'تاریخ ویرایش',
        ];
    }

    public function map($item): array
    {
        $transfer = $item->transfer;
        $customerName = trim(($transfer?->customer?->first_name ?? '') . ' ' . ($transfer?->customer?->last_name ?? ''));
        $variantParts = array_filter([
            $item->variant?->modelList?->model_name,
            $item->variant?->color?->name,
            $item->variant?->variant_name ?: $item->variant_name,
            $item->variant?->variant_code ?: $item->variant_code,
        ]);

        $category = $item->product?->category;
        $rootCategory = $category?->parent ?: $category;
        $subcategory = $category?->parent ? $category : null;

        return [
            $transfer?->reference ?: ('TR-' . $transfer?->id),
            JalaliDate::dateTime($transfer?->transferred_at),
            $customerName !== '' ? $customerName : ($transfer?->beneficiary_name ?: '—'),
            $transfer?->customer?->crm_customer_id ?: $transfer?->customer?->mobile,
            $item->product?->code ?: $item->product?->sku,
            $item->product?->name,
            $rootCategory?->name,
            $subcategory?->name ?: '—',
            implode(' / ', array_unique($variantParts)) ?: '—',
            (int) ($transfer?->returned_items_count ?? 0),
            (int) ($transfer?->returned_items_total_amount ?? $transfer?->total_amount ?? 0),
            (int) $item->quantity,
            $item->product?->unit ?: 'عدد',
            (int) ($item->line_total ?? ((int) $item->unit_price * (int) $item->quantity)),
            WarehouseTransfer::returnReasonOptions()[$transfer?->return_reason] ?? '—',
            $transfer?->note,
            $transfer?->user?->name,
            JalaliDate::dateTime($transfer?->created_at),
            $transfer?->updated_at && !$transfer->updated_at->equalTo($transfer->created_at) ? JalaliDate::dateTime($transfer->updated_at) : '',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft(true);
                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('A1:S1')->getFont()->setBold(true);
                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                $sheet->freezePane('A2');
            },
        ];
    }
}
