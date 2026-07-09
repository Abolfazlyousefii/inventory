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


    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item): void {
            $item->assertValidSnapshotPrice();
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
