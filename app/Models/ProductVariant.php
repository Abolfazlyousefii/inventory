<?php

namespace App\Models;

use App\Services\ProductVariantStructureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    public function save(array $options = []): bool
    {
        if (! $this->exists || $this->isDirty('sell_price') || $this->isDirty('product_id')) {
            // Keep the parent-price lock through INSERT/UPDATE even outside a caller transaction.
            return $this->getConnection()->transaction(fn () => parent::save($options), 3);
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            if (! $variant->exists || $variant->isDirty('sell_price') || $variant->isDirty('product_id')) {
                $product = Product::query()->whereKey($variant->product_id)->lockForUpdate()->first();
                if ($product?->uniform_sale_price !== null) {
                    $variant->sell_price = (int) $product->uniform_sale_price;
                }
            }
        });
    }

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
