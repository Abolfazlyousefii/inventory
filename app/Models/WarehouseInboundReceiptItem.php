<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseInboundReceiptItem extends Model
{
    protected $fillable = [
        'receipt_id', 'source_item_type', 'source_item_id', 'product_id', 'product_variant_id',
        'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot', 'expected_quantity',
        'accepted_quantity', 'suggested_warehouse_id', 'received_warehouse_id', 'condition',
        'reason', 'source_meta', 'note', 'stock_movement_id',
    ];

    protected $casts = [
        'expected_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'source_meta' => 'array',
    ];

    public function receipt()
    {
        return $this->belongsTo(WarehouseInboundReceipt::class, 'receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function suggestedWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'suggested_warehouse_id');
    }

    public function receivedWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'received_warehouse_id');
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function getDifferenceAttribute(): int
    {
        return (int) $this->accepted_quantity - (int) $this->expected_quantity;
    }

    public static function reasonLabels(): array
    {
        return [
            'physical_shortage' => 'کسری فیزیکی',
            'item_removed' => 'حذف کالا از فاکتور',
            'quantity_decreased' => 'کاهش تعداد فاکتور',
            'variant_changed' => 'تغییر تنوع کالا',
            'finance_correction' => 'اصلاح مالی',
            'invoice_correction' => 'اصلاح فاکتور',
            'customer_cancelled' => 'انصراف مشتری',
            'wrong_item' => 'ثبت کالای اشتباه',
            'warehouse_correction' => 'اصلاح انبار',
            'replacement' => 'جایگزینی کالا',
            'invoice_cancelled' => 'لغو فاکتور',
            'sales_return' => 'برگشت از فروش',
            'other' => 'سایر',
        ];
    }
}
