<?php

namespace App\Services;

use App\Models\{Product, Warehouse, WarehouseStock};
use Illuminate\Support\Collection;

/** Read-only warehouse display; canonical quantities and reservations remain unchanged. */
class ProductWarehouseStockOverview
{
    public function groups(): Collection
    {
        $warehouses = Warehouse::orderBy('id')->get(['id', 'name', 'type', 'parent_id', 'personnel_name'])->keyBy('id');
        $groups = $warehouses->groupBy(function (Warehouse $warehouse) use ($warehouses) {
            $root = $warehouse;
            $visited = [];
            while ($root->type === 'personnel' && $root->parent_id && !isset($visited[$root->id])) {
                $visited[$root->id] = true;
                $parent = $warehouses->get($root->parent_id);
                if (!$parent || $parent->type !== 'personnel' || isset($visited[$parent->id])) break;
                $root = $parent;
            }
            return $root->id;
        });
        $order = ['central'=>0, 'return'=>1, 'personnel'=>2, 'scrap'=>3, 'showroom'=>4];
        return $groups->map(fn (Collection $members, $id) => ['warehouse'=>$warehouses->get($id), 'members'=>$members])
            ->sortBy(fn ($group) => sprintf('%02d-%010d', $order[$group['warehouse']->type] ?? 5, $group['warehouse']->id));
    }

    public function rows(Product $product, Collection $variantIds, Collection $reservedByVariant, int $centralId, bool $hasVariants): Collection
    {
        $groups = $this->groups();
        $stocks = WarehouseStock::where('product_id', $product->id)
            ->when($hasVariants, fn ($q) => $q->whereIn('product_variant_id', $variantIds))
            ->when(!$hasVariants, fn ($q) => $q->whereNull('product_variant_id'))
            ->select('warehouse_id', 'product_variant_id')->selectRaw('SUM(quantity) AS quantity')
            ->groupBy('warehouse_id', 'product_variant_id')->get()
            ->keyBy(fn ($stock) => (int) $stock->product_variant_id.':'.(int) $stock->warehouse_id);
        $rows = collect();
        foreach ($hasVariants ? $variantIds : [null] as $variantId) {
            foreach ($groups as $group) {
                $warehouse = $group['warehouse'];
                $available = 0;
                $reserved = 0;
                $children = [];
                foreach ($group['members'] as $member) {
                    $quantity = (int) ($stocks->get((int) $variantId.':'.$member->id)?->quantity ?? 0);
                    $memberReserved = $hasVariants && (int) $member->id === $centralId
                        ? (int) ($reservedByVariant[$variantId] ?? 0) : 0;
                    $available += $quantity;
                    $reserved += $memberReserved;
                    if ($warehouse->type === 'personnel' && ((int) $member->id !== (int) $warehouse->id || $quantity !== 0)) {
                        $direct = (int) $member->id === (int) $warehouse->id;
                        $children[] = ['id'=>(int) $member->id,
                            'name'=>$direct ? 'ثبت مستقیم در '.$warehouse->name : $member->name.($member->personnel_name ? ' · '.$member->personnel_name : ''),
                            'total_quantity'=>$quantity + $memberReserved, 'reserved_quantity'=>$memberReserved,
                            'available_quantity'=>$quantity, 'has_warning'=>$quantity < 0, 'is_direct'=>$direct];
                    }
                }
                $rows->push(['variant_id'=>$variantId, 'variant_title'=>$hasVariants ? null : 'کل کالا', 'display_code'=>null,
                    'warehouse_id'=>(int) $warehouse->id, 'warehouse_name'=>(string) $warehouse->name,
                    'total_quantity'=>$available + $reserved, 'reserved_quantity'=>$reserved, 'available_quantity'=>$available,
                    'has_over_reserved_warning'=>$available < 0, 'children'=>$children]);
            }
        }
        return $rows;
    }
}
