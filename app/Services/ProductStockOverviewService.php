<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only summaries. Commercial totals are not a fabricated opening/closing ledger. */
class ProductStockOverviewService
{
    public const LABELS = [
        'purchases' => 'خرید کالا', 'sales' => 'فروش کالا', 'returns' => 'دریافت و برگشت کالا',
        'transfers' => 'انتقال بین انبارها', 'stocktakes' => 'انبارگردانی و اصلاح موجودی',
        'reservations' => 'رزرو سفارش‌ها', 'other' => 'سایر سوابق',
    ];

    public function summarize(Collection $rows, int $productId, ?int $variantId): array
    {
        foreach ($rows as $row) $row->overview_category = $this->category($row);
        $groups = collect(self::LABELS)->map(function ($label, $key) use ($rows) {
            $items = $rows->where('overview_category', $key);
            $quantities = $items->filter(function ($row) use ($key) {
                if ($key === 'sales') return str_starts_with((string)$row->id, 'invoice-item-') && $row->type !== 'info';
                if ($key === 'returns') return isset($row->accepted_quantity)
                    || ($row instanceof StockMovement && in_array($row->reason,['return','sales_return'],true) && !isset($row->inbound_context));
                if ($key === 'purchases') return $row->reason === 'purchase' || $row->history_kind === 'سند خرید';
                return false;
            });
            return [
                'label' => $label, 'records' => $items->count(),
                'quantity' => $quantities->sum(fn ($row) => (int)($row->accepted_quantity ?? $row->quantity)),
                'in' => $key === 'transfers' ? $items->whereIn('type',['in','document_in'])->sum('quantity')
                    : $items->where('evidence_level', 'recorded')->sum(fn ($row) => max(0, (int)$row->available_delta)),
                'out' => $key === 'transfers' ? $items->whereIn('type',['out','document_out'])->sum('quantity')
                    : $items->where('evidence_level', 'recorded')->sum(fn ($row) => max(0, -(int)$row->available_delta)),
                'incomplete' => $items->where('evidence_level', 'incomplete')->count(),
            ];
        });
        $hasVariants = DB::table('product_variants')->where('product_id', $productId)->exists();
        $stocks = DB::table('warehouse_stocks')->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when(!$variantId && $hasVariants, fn ($q) => $q->whereNotNull('product_variant_id'))
            ->selectRaw('warehouse_id, SUM(quantity) AS quantity')->groupBy('warehouse_id')->pluck('quantity', 'warehouse_id');
        $balances = app(ProductWarehouseStockOverview::class)->groups()->map(fn ($group) => (object)[
            'id' => $group['warehouse']->id, 'name' => $group['warehouse']->name,
            'quantity' => $group['members']->sum(fn ($member) => (int)($stocks[$member->id] ?? 0)),
        ]);
        $issues = $rows->filter(fn ($row) => ($row->integrity_issue ?? false) && isset($row->accepted_quantity))->values();
        return compact('groups', 'balances', 'issues');
    }

    private function category(object $row): string
    {
        if ($row->history_kind === 'سابقه رزرو' || in_array($row->reason, ['reservation', 'reserve', 'reservation_release'], true)) return 'reservations';
        if (str_starts_with((string)$row->id, 'invoice-item-')) return 'sales';
        if ($row->history_kind === 'سند حواله' && str_contains($row->reason,'مرجوعی')) return 'returns';
        if ($row->reason === 'transfer' || $row->history_kind === 'سند حواله') return 'transfers';
        if (isset($row->accepted_quantity) || $row->history_kind === 'سند برگشت از فروش'
            || in_array($row->reason, ['return','sales_return','invoice_cancel_return','invoice_adjustment_return'], true)) return 'returns';
        if ($row->reason === 'purchase' || $row->history_kind === 'سند خرید') return 'purchases';
        if ($row->history_kind === 'سند انبارگردانی' || in_array($row->reason, ['adjustment','stocktake','stock_count','purchase_item_added','purchase_item_quantity_changed','purchase_item_removed','purchase_return','purchase_return_restore','sale'], true)) {
            return $row->reason === 'sale' ? 'sales' : 'stocktakes';
        }
        return 'other';
    }
}
