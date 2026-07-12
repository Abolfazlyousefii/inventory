<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnDocumentItem extends Model
{
    public const CONDITION_HEALTHY = 'healthy';
    public const CONDITION_DAMAGED = 'damaged';

    protected $fillable = [
        'document_id', 'invoice_item_id', 'product_id', 'product_variant_id',
        'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot', 'item_condition',
        'destination_warehouse_id', 'sold_quantity_snapshot', 'previous_returned_quantity_snapshot',
        'return_quantity', 'unit_price_snapshot', 'line_discount_snapshot',
        'allocated_invoice_discount_snapshot', 'refund_unit_price', 'refund_amount',
        'purchase_price', 'sell_price', 'new_product_payload', 'sort_order',
    ];

    protected $casts = [
        'sold_quantity_snapshot' => 'integer',
        'previous_returned_quantity_snapshot' => 'integer',
        'return_quantity' => 'integer',
        'unit_price_snapshot' => 'integer',
        'line_discount_snapshot' => 'integer',
        'allocated_invoice_discount_snapshot' => 'integer',
        'refund_unit_price' => 'integer',
        'refund_amount' => 'integer',
        'purchase_price' => 'integer',
        'sell_price' => 'integer',
        'new_product_payload' => 'array',
    ];

    public function document(): BelongsTo { return $this->belongsTo(SalesReturnDocument::class, 'document_id'); }
    public function invoiceItem(): BelongsTo { return $this->belongsTo(InvoiceItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function destinationWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'destination_warehouse_id'); }

    public function isHealthy(): bool { return $this->item_condition === self::CONDITION_HEALTHY; }
    public function isDamaged(): bool { return $this->item_condition === self::CONDITION_DAMAGED; }
    public function isExistingProduct(): bool { return !empty($this->product_id) || !empty($this->product_variant_id); }
    public function hasNewProductPayload(): bool { return !empty($this->new_product_payload); }
}
