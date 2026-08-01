<?php

namespace App\Models;

use App\Services\ProductVariantStructureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'is_active',
        'sales_enabled',
        'variant_name',
        'model_list_id',
        'color_id',
        'variety_name',
        'variety_code',
        'variant_code',
        'variety_id',
        'unique_key',

        'buy_price',
        'sell_price',
        'stock',
        'reserved',

        'synced_at',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sales_enabled' => 'boolean',
        'buy_price'  => 'integer',
        'sell_price' => 'integer',
        'stock'      => 'integer',
        'reserved'   => 'integer',
        'synced_at'  => 'datetime',
    ];

    /**
     * هر ایجاد، ویرایش یا حذف واریانت باید محصول مرتبط را
     * دوباره وارد صف همگام‌سازی Inventory با Site کند.
     */
    protected static function booted(): void
    {
        static::created(function (ProductVariant $variant): void {
            static::markProductsAsPending([
                $variant->product_id,
            ]);
        });

        static::updated(function (ProductVariant $variant): void {
            /*
             * اگر product_id تغییر کرده باشد، هم محصول قبلی و هم محصول جدید
             * باید دوباره همگام‌سازی شوند.
             */
            static::markProductsAsPending([
                $variant->getOriginal('product_id'),
                $variant->product_id,
            ]);
        });

        static::deleted(function (ProductVariant $variant): void {
            static::markProductsAsPending([
                $variant->product_id,
            ]);
        });
    }

    /**
     * فلگ‌های همگام‌سازی محصولات مرتبط را بدون اجرای Event مدل Product صفر می‌کند.
     *
     * @param array<int, mixed> $productIds
     */
    private static function markProductsAsPending(array $productIds): void
    {
        $productIds = collect($productIds)
            ->filter(static function ($productId): bool {
                return is_numeric($productId)
                       && (int) $productId > 0;
            })
            ->map(static fn ($productId): int => (int) $productId)
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return;
        }

        $syncFlags = array_values(array_filter([
            Schema::hasColumn('products', 'inventory_to_site_synced') ? 'inventory_to_site_synced' : null,
            Schema::hasColumn('products', 'site_to_inventory_verified') ? 'site_to_inventory_verified' : null,
        ]));

        if ($syncFlags === []) {
            return;
        }

        DB::table('products')
            ->whereIn('id', $productIds)
            ->update(array_fill_keys($syncFlags, false));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function modelList()
    {
        return $this->belongsTo(ModelList::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function warehouseStocks()
    {
        return $this->hasMany(WarehouseStock::class, 'product_variant_id');
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, (int) ($this->stock ?? 0));
    }

    public function getBarcodeAttribute(): ?string
    {
        return $this->variant_code;
    }

    public function getSkuAttribute(): ?string
    {
        return $this->variant_code;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValidForProductStructure(Builder $query, Product $product): Builder
    {
        return app(ProductVariantStructureService::class)->applyValidConstraints($query, $product);
    }

    public function locationStocks()
    {
        return $this->hasMany(WarehouseLocationStock::class, 'product_variant_id');
    }

    public function locationMovements()
    {
        return $this->hasMany(WarehouseLocationMovement::class, 'product_variant_id');
    }
}
