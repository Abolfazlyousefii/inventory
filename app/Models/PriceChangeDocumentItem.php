<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceChangeDocumentItem extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_APPLIED = 'applied';

    protected $fillable = ['price_change_document_id','product_id','product_variant_id','product_name_snapshot','variant_name_snapshot','sku_snapshot','old_price','new_price','change_type','change_value','rounding_mode','status','error_message','validation_details','applied_at'];
    protected $casts = ['old_price' => 'integer', 'new_price' => 'integer', 'change_value' => 'decimal:2', 'validation_details' => 'array', 'applied_at' => 'datetime'];

    public function document(): BelongsTo { return $this->belongsTo(PriceChangeDocument::class, 'price_change_document_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
