<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnDocumentItem extends Model
{
    public const SOURCE_INVOICE_ITEM = 'invoice_item';
    public const SOURCE_EXISTING_PRODUCT = 'existing_product';
    public const SOURCE_NEW_PRODUCT = 'new_product';
    public const CONDITION_HEALTHY = 'healthy';
    public const CONDITION_DAMAGED = 'damaged';

    protected $fillable = ['document_id','invoice_item_id','product_id','product_variant_id','product_name_snapshot','variant_name_snapshot','sku_snapshot','barcode_snapshot','item_source','item_condition','destination_warehouse_id','sold_quantity_snapshot','previously_returned_quantity_snapshot','return_quantity','unit_price_snapshot','line_discount_snapshot','allocated_invoice_discount_snapshot','refund_unit_price','refund_amount','purchase_price','sell_price','new_product_payload','created_product_id','created_variant_id','sort_order'];
    protected $casts = ['new_product_payload'=>'array','return_quantity'=>'integer','refund_unit_price'=>'integer','refund_amount'=>'integer','purchase_price'=>'integer','sell_price'=>'integer'];
    public function document(){ return $this->belongsTo(SalesReturnDocument::class, 'document_id'); }
    public function invoiceItem(){ return $this->belongsTo(InvoiceItem::class); }
    public function product(){ return $this->belongsTo(Product::class); }
    public function variant(){ return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function destinationWarehouse(){ return $this->belongsTo(Warehouse::class, 'destination_warehouse_id'); }
    public function createdProduct(){ return $this->belongsTo(Product::class, 'created_product_id'); }
    public function createdVariant(){ return $this->belongsTo(ProductVariant::class, 'created_variant_id'); }
    public static function conditionLabels(): array { return [self::CONDITION_HEALTHY=>'سالم', self::CONDITION_DAMAGED=>'مرجوعی / معیوب']; }
}
