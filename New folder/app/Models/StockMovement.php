<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';

    public const REASON_PURCHASE = 'purchase';
    public const REASON_SALE = 'sale';
    public const REASON_RETURN = 'return';
    public const REASON_TRANSFER = 'transfer';
    public const REASON_ADJUSTMENT = 'adjustment';
    public const REASON_PURCHASE_ITEM_ADDED = 'purchase_item_added';
    public const REASON_PURCHASE_ITEM_CHANGED = 'purchase_item_quantity_changed';
    public const REASON_PURCHASE_ITEM_REMOVED = 'purchase_item_removed';

    public const TRANSACTION_PURCHASE_ADJUSTMENT = 'purchase_adjustment';
    public const TRANSACTION_SALES_HAVALEH_ADJUSTMENT = 'sales_havaleh_adjustment';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'user_id',
        'type',
        'reason',
        'transaction_type',
        'quantity',
        'stock_before',
        'stock_after',
        'note',
        'reference',
        'reference_type',
        'reference_id',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function user() { return $this->belongsTo(User::class); }
}
