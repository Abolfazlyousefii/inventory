<?php

// Standalone verification of the existing canonical retry snapshot logic.
// Run: php tests/standalone/site_retry_snapshot.php
namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
    {
        protected array $items;
        public function __construct(array $items = []) { $this->items = $items; }
        public function getIterator(): \Traversable { yield from $this->items; }
        public function count(): int { return count($this->items); }
        public function offsetExists(mixed $key): bool { return isset($this->items[$key]); }
        public function offsetGet(mixed $key): mixed { return $this->items[$key] ?? null; }
        public function offsetSet(mixed $key, mixed $value): void { $this->items[$key] = $value; }
        public function offsetUnset(mixed $key): void { unset($this->items[$key]); }
        public function put($key, $item): static { $this->items[$key] = $item; return $this; }
        public function first(): mixed { return reset($this->items) ?: null; }
        public function isEmpty(): bool { return !$this->items; }
        public function values(): static { return new static(array_values($this->items)); }
        public function all(): array { return $this->items; }
        public function pluck(string $field): static { return new static(array_map(fn ($item) => $item->$field, $this->items)); }
    }
}
namespace Illuminate\Database\Eloquent {
    class Collection extends \Illuminate\Support\Collection
    {
        public function load(string $relation): static { return $this; }
    }
}
namespace App\Models {
    class InventoryWebhookLog
    {
        public function __construct(public array $payload) {}
    }
    class ProductVariant
    {
        public static array $records = [];
        public static function query(): VariantQuery { return new VariantQuery(); }
    }
    class VariantQuery
    {
        private array $filters = [];
        public function whereKey($key): static { $this->filters['id'] = $key; return $this; }
        public function where($field, $value): static { $this->filters[$field] = $value; return $this; }
        public function limit($value): static { return $this; }
        public function get(): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection(array_values(array_filter(ProductVariant::$records, function ($item) {
                foreach ($this->filters as $field => $value) if ($item->$field != $value) return false;
                return true;
            })));
        }
        public function first(): ?object { return $this->get()->first(); }
    }
    class WarehouseStock
    {
        public static array $rows = [];
        public static function query(): StockQuery { return new StockQuery(); }
    }
    class StockQuery
    {
        private array $warehouseIds = [];
        private array $variantIds = [];
        public function where(string $field, $value): static { if ($field === 'warehouse_id') $this->warehouseIds = [$value]; return $this; }
        public function whereIn(string $field, array $ids): static { if ($field === 'warehouse_id') $this->warehouseIds = $ids; else $this->variantIds = $ids; return $this; }
        public function selectRaw(string $sql): static { return $this; }
        public function groupBy(string $field): static { return $this; }
        public function pluck(string $value, string $key): \Illuminate\Support\Collection
        {
            $totals = [];
            foreach (WarehouseStock::$rows as $row) {
                if (!in_array($row->warehouse_id, $this->warehouseIds, true) || !in_array($row->product_variant_id, $this->variantIds, true)) continue;
                $id = $row->product_variant_id;
                $totals[$id] = ($totals[$id] ?? 0) + $row->quantity;
            }
            return new \Illuminate\Support\Collection($totals);
        }
    }
}
namespace App\Services {
    class AutomaticBundleAvailabilityService
    {
        public function available(int $bundleVariantId): int { return $bundleVariantId === 8 ? 3 : 0; }
    }
    class WarehouseStockService
    {
        public static array $siteWarehouses = [1];
        public static function centralWarehouseId(): int { return 1; }
        public static function siteSellableWarehouseIds(): array { return self::$siteWarehouses; }
    }
}
namespace {
    function collect(array $rows = []): \Illuminate\Support\Collection { return new \Illuminate\Support\Collection($rows); }
    function app(string $class): object { return new $class(); }
    require_once __DIR__.'/../../app/Services/AriyajanebiSyncService.php';

    $product = (object) ['id' => 10, 'name' => 'Item', 'is_bundle' => false, 'bundle_stock_mode' => null, 'code' => 'X10', 'sku' => 'X10', 'barcode' => 'X10'];
    \App\Models\ProductVariant::$records = [(object) [
        'id' => 7, 'variety_id' => 'remote-7', 'product_id' => 10,
        'sell_price' => 1000, 'product' => $product,
        'variant_name' => 'Normal', 'variant_code' => 'SKU7',
        'variety_name' => null, 'variety_code' => null, 'sku' => 'SKU7',
    ]];
    \App\Models\WarehouseStock::$rows = [
        (object) ['warehouse_id' => 1, 'product_variant_id' => 7, 'quantity' => 5],
        (object) ['warehouse_id' => 2, 'product_variant_id' => 7, 'quantity' => 90],
    ];

    $old = new \App\Models\InventoryWebhookLog(['payload' => [
        'varieties[0][id]' => 'remote-7', 'varieties[0][price]' => '2000', 'varieties[0][balance]' => '20',
    ]]);
    $references = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'referencesFromLog'))->invoke(null, $old);
    [$payload, $preview] = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'currentSnapshot'))->invoke(null, $references);
    if ($payload['varieties[0][price]'] !== '1000' || $payload['varieties[0][balance]'] !== '5' || count($preview) !== 1) {
        throw new \RuntimeException('Retry reused stale price/stock, or counted a noncentral warehouse.');
    }
    $old = new \App\Models\InventoryWebhookLog(['variants' => [['id' => 'remote-7', 'variant_id' => 7, 'balance' => '20']]]);
    $references = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'referencesFromLog'))->invoke(null, $old);
    [$payload] = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'currentSnapshot'))->invoke(null, $references);
    if ($payload['varieties[0][balance]'] !== '5') {
        throw new \RuntimeException('Modern retry variant preview reused stale stock.');
    }
    $references = [['variant_id' => 7, 'variety_id' => 'reassigned-id']];
    [$payload, $preview] = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'currentSnapshot'))->invoke(null, $references);
    if ($payload !== [] || $preview !== []) {
        throw new \RuntimeException('Retry allowed a stale remote identifier to address another product.');
    }
    // A deliberately authorized warehouse may be added, but the default
    // MUST NOT include returns or personnel stock.
    \App\Services\WarehouseStockService::$siteWarehouses = [1, 2];
    [$configuredPayload] = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'currentSnapshot'))->invoke(null, [
        ['variant_id' => 7, 'variety_id' => 'remote-7'],
    ]);
    if ($configuredPayload['varieties[0][balance]'] !== '95') {
        throw new \RuntimeException('An explicitly authorized warehouse was not included in the site snapshot.');
    }
    // Automatic bundles have no direct sellable warehouse balance: their site
    // availability is the protected component allocation, not a warehouse sum.
    \App\Models\ProductVariant::$records[] = (object) [
        'id' => 8, 'variety_id' => 'bundle-8', 'product_id' => 11,
        'sell_price' => 2500,
        'product' => (object) ['id' => 11, 'name' => 'Kit', 'is_bundle' => true,
            'bundle_stock_mode' => 'automatic', 'code' => 'K', 'sku' => 'K', 'barcode' => 'K'],
        'variant_name' => 'Kit', 'variant_code' => 'KIT', 'variety_name' => null,
        'variety_code' => null, 'sku' => 'KIT',
    ];
    \App\Services\WarehouseStockService::$siteWarehouses = [1];
    [$bundlePayload] = (new \ReflectionMethod(\App\Services\AriyajanebiSyncService::class, 'currentSnapshot'))
        ->invoke(null, [['variant_id' => 8, 'variety_id' => 'bundle-8']]);
    if ($bundlePayload['varieties[0][balance]'] !== '3') {
        throw new \RuntimeException('Automatic bundle site balance ignored its protected component availability.');
    }
    echo "5 site retry snapshot assertions passed.\n";
}
