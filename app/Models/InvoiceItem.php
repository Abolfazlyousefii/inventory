<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'variant_id',
        'quantity',
        'price',
        'line_total',
        'sort_order',
        'line_discount_amount',
    ];


    /**
     * Ensure the item has a valid variant set.
     * If no variant is set, try to assign the product's first variant.
     * If that fails, throw a validation exception with a clear message.
     */
    public function ensureVariantIsSet(): void
    {
        // If no product is linked, the item itself is invalid – but we let other validation handle it.
        if (! $this->product_id) {
            return; // or throw an exception if your system requires a product always
        }

        $product = $this->product()->withoutGlobalScopes()->first();
        if (! $product) {
            return; // product was deleted – skip or handle separately
        }

        $hasVariants = ProductVariant::query()
            ->where('product_id', $product->id)
            // Consider adding ->where('is_active', true) if you only want purchasable variants
            ->exists();

        if ($hasVariants && empty($this->variant_id)) {
            throw ValidationException::withMessages([
                'variant_id' => 'برای کالای دارای تنوع، انتخاب تنوع الزامی است.',
            ]);
        }
    }

    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item): void {
            $item->assertValidSnapshotPrice();
            $item->ensureVariantIsSet();
            $item->line_total = max(((int) $item->quantity * (int) $item->price) - (int) ($item->line_discount_amount ?? 0), 0);
        });
    }

    public function assertValidSnapshotPrice(): void
    {
        if ((int) $this->quantity > 0 && (int) $this->price <= 0) {
            $name = trim(($this->product?->name ?? 'نامشخص') . ' / ' . ($this->variant?->variant_name ?: $this->variant?->variety_name ?: ($this->variant_id ? ('#' . $this->variant_id) : '')));

            throw ValidationException::withMessages([
                'price' => "قیمت کالا/تنوع {$name} صفر است و امکان ثبت فاکتور وجود ندارد.",
            ]);
        }
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
    public function product()
{
    return $this->belongsTo(\App\Models\Product::class);
}

public function variant()
{
    return $this->belongsTo(\App\Models\ProductVariant::class, 'variant_id');
}

}
