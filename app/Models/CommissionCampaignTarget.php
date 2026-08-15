<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionCampaignTarget extends Model
{
    protected $fillable = ['commission_campaign_id', 'target_type', 'target_id', 'target_key', 'category_id', 'product_id', 'product_variant_id'];

    public function campaign()
    {
        return $this->belongsTo(CommissionCampaign::class, 'commission_campaign_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return match ($this->target_type) {
            'category' => $this->category?->name ?? 'دسته انتخاب‌شده',
            'product' => $this->product?->name ?? 'کالای انتخاب‌شده',
            'variant' => $this->variant?->variant_name ?? $this->variant?->variant_code ?? 'تنوع انتخاب‌شده',
            default => 'قلم انتخاب‌شده',
        };
    }
}
