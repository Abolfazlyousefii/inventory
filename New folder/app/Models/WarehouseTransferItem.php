<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ProductVariant;

class WarehouseTransferItem extends Model
{
    protected $fillable = [
        'warehouse_transfer_id',
        'invoice_item_id',
        'product_id',
        'product_variant_id',
        'variant_name',
        'variant_code',
        'quantity',
        'unit_price',
        'line_total',
        'return_kind',
        'destination_warehouse_id',
        'personnel_asset_code',
    ];

    public function transfer()
    {
        return $this->belongsTo(WarehouseTransfer::class, 'warehouse_transfer_id');
    }

    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function effectiveReturnKind(): string
    {
        if (in_array($this->return_kind, ['healthy', 'damaged'], true)) {
            return $this->return_kind;
        }

        return ($this->destinationWarehouse?->type === 'return') ? 'damaged' : 'healthy';
    }

    public function returnKindLabel(): string
    {
        return $this->effectiveReturnKind() === 'damaged' ? 'مرجوعی' : 'سالم';
    }
}
