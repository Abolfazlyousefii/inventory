<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Read-only inventory projection. The only writes are review notes and their history. */
class WarehouseDailySalesService
{
    public function warehouseId(): int
    {
        // Do not use centralWarehouseId(): that helper may create a warehouse.
        $ids = DB::table('warehouses')->where('type', 'central')->pluck('id');
        abort_unless($ids->count() === 1, 422, 'برای گزارش باید دقیقاً یک انبار مرکزی تعریف شده باشد.');
        return (int) $ids->first();
    }

    public function boundaries(string $day): array
    {
        $start = Carbon::parse($day, config('app.timezone'))->startOfDay();
        return [$start->format('Y-m-d H:i:s'), $start->copy()->addDay()->format('Y-m-d H:i:s')];
    }

    public function sales(string $day): Builder
    {
        [$start, $end] = $this->boundaries($day);
        // Match the existing invoice report's creation-date and cancellation semantics.
        return DB::table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->whereNotIn('i.status', Invoice::cancelledStatuses())->whereNull('i.cancelled_at')
            ->where('i.created_at', '>=', $start)->where('i.created_at', '<', $end);
    }

    public function movements(string $day, int $warehouse): Builder
    {
        [$start, $end] = $this->boundaries($day);
        return DB::table('stock_movements as m')->where('m.warehouse_id', $warehouse)
            ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end);
    }

    public function rows(string $day, int $warehouse, string $search = '', string $filter = 'all'): Builder
    {
        $sales = $this->sales($day)->selectRaw('ii.product_id, COALESCE(ii.variant_id, 0) variant_id, SUM(ii.quantity) sales')
            ->groupBy('ii.product_id', 'ii.variant_id');
        $flows = $this->movements($day, $warehouse)->selectRaw("m.product_id, COALESCE(m.product_variant_id, 0) variant_id, SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE 0 END) incoming, SUM(CASE WHEN m.type = 'out' THEN m.quantity ELSE 0 END) outgoing")
            ->groupBy('m.product_id', 'm.product_variant_id');
        $notes = DB::table('warehouse_daily_counts')->where('review_date', $day)->where('warehouse_id', $warehouse);
        $keys = $this->sales($day)->selectRaw('ii.product_id, COALESCE(ii.variant_id, 0) variant_id')
            ->union($this->movements($day, $warehouse)->selectRaw('m.product_id, COALESCE(m.product_variant_id, 0) variant_id'))
            ->union((clone $notes)->select('product_id', 'variant_id'));
        $stocks = DB::table('warehouse_stocks')->where('warehouse_id', $warehouse)
            ->selectRaw('product_id, COALESCE(product_variant_id, 0) variant_id, SUM(quantity) stock')
            ->groupBy('product_id', 'product_variant_id');
        $hasVariants = DB::table('product_variants')->select('product_id')->distinct();
        $rows = DB::query()->fromSub($keys, 'k')
            ->leftJoin('products as p', 'p.id', '=', 'k.product_id')
            ->leftJoin('product_variants as v', function ($j) { $j->on('v.id', '=', 'k.variant_id')->on('v.product_id', '=', 'k.product_id'); })
            ->leftJoinSub($hasVariants, 'hv', 'hv.product_id', '=', 'k.product_id');
        foreach (['s' => $sales, 'f' => $flows, 'n' => $notes, 'w' => $stocks] as $alias => $query) {
            $rows->leftJoinSub($query, $alias, function ($j) use ($alias) {
                $j->on("$alias.product_id", '=', 'k.product_id')->on("$alias.variant_id", '=', 'k.variant_id');
            });
        }
        $current = "CASE WHEN p.id IS NULL OR (k.variant_id > 0 AND v.id IS NULL) OR (k.variant_id = 0 AND hv.product_id IS NOT NULL) THEN NULL ELSE COALESCE(w.stock, 0) END";
        $rows->selectRaw("k.product_id, k.variant_id, COALESCE(p.name, n.product_name) name, COALESCE(v.variant_name, n.variant_name, 'بدون تنوع') variant_name, COALESCE(v.variant_code, n.code, p.code, p.sku) code, COALESCE(s.sales, 0) sales, COALESCE(f.incoming, 0) incoming, COALESCE(f.outgoing, 0) outgoing, $current current_stock, n.physical_quantity, n.stock_snapshot, n.note, COALESCE(n.version, 0) version, n.updated_at counted_at, u.name counted_by")
            ->leftJoin('users as u', 'u.id', '=', 'n.updated_by');
        if ($search !== '') {
            $rows->where(function ($q) use ($search) {
                foreach (['p.name', 'v.variant_name', 'v.variant_code', 'p.code', 'p.sku', 'n.product_name', 'n.code'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }
        if ($filter === 'pending') $rows->whereNull('n.id');
        if ($filter === 'issues') $rows->whereColumn('n.physical_quantity', '!=', 'n.stock_snapshot');
        return $rows;
    }

    public function save(string $day, int $warehouse, array $input, int $actor): array
    {
        $product = Product::findOrFail($input['product_id']);
        $variantId = (int) $input['variant_id'];
        $variant = $variantId ? ProductVariant::where('product_id', $product->id)->findOrFail($variantId) : null;
        abort_if(! $variantId && ProductVariant::where('product_id', $product->id)->exists(), 422, 'کالای دارای تنوع باید برای هر تنوع جدا شمارش شود.');
        abort_unless($this->rows($day, $warehouse)->where('k.product_id', $product->id)->where('k.variant_id', $variantId)->exists(), 422, 'این کالا در گزارش روز انتخابی نیست.');
        try {
            return DB::transaction(function () use ($day, $warehouse, $input, $actor, $product, $variant, $variantId) {
                $key = ['review_date' => $day, 'warehouse_id' => $warehouse, 'product_id' => $product->id, 'variant_id' => $variantId];
                $existing = DB::table('warehouse_daily_counts')->where($key)->lockForUpdate()->first();
                abort_if((int) ($existing?->version ?? 0) !== (int) $input['version'], 409, 'این بررسی توسط کاربر دیگری تغییر کرده؛ لیست را تازه کنید.');
                $stock = (int) DB::table('warehouse_stocks')->where('warehouse_id', $warehouse)->where('product_id', $product->id)
                    ->where('product_variant_id', $variantId ?: null)->sum('quantity');
                abort_if($stock !== (int) $input['expected_stock'], 409, 'موجودی از زمان نمایش تغییر کرده؛ لیست را تازه کنید و دوباره شمارش کنید.');
                $data = ['physical_quantity' => (int) $input['physical_quantity'], 'stock_snapshot' => $stock,
                    'note' => $input['note'] ?? null, 'product_name' => $product->name,
                    'variant_name' => $variant?->variant_name, 'code' => $variant?->variant_code ?: $product->code,
                    'updated_by' => $actor, 'version' => (int) ($existing?->version ?? 0) + 1, 'updated_at' => now()];
                if ($existing) DB::table('warehouse_daily_counts')->where('id', $existing->id)->update($data);
                else $data['id'] = DB::table('warehouse_daily_counts')->insertGetId($key + $data + ['created_at' => now()]);
                DB::table('warehouse_daily_count_history')->insert(['count_id' => $existing?->id ?? $data['id'],
                    'user_id' => $actor, 'version' => $data['version'], 'snapshot' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
                return $data;
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) abort(409, 'این بررسی هم‌زمان تغییر کرده؛ لیست را تازه کنید.');
            throw $e;
        }
    }
}
