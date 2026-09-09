<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SellerSalesDocumentItem extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REASSIGNED = 'reassigned';

    protected $fillable = [
        'seller_sales_document_id',
        'invoice_id',
        'status',
        'active_invoice_id',
        'invoice_number_snapshot',
        'invoice_date_snapshot',
        'customer_name_snapshot',
        'invoice_total_snapshot',
        'reassigned_to_seller_id',
        'reassigned_at',
        'reassignment_audit_id',
        'product_id', 'product_variant_id',
        'product_name_snapshot', 'variant_name_snapshot', 'quantity_snapshot',
        'rate_snapshot', 'rate_source_type', 'rate_source_id', 'rate_rule_id',
        'item_net_amount', 'commission_amount', 'missing_rate', 'calculation_version',
    ];

    protected $casts = [
        'invoice_date_snapshot' => 'datetime',
        'invoice_total_snapshot' => 'integer',
        'active_invoice_id' => 'integer',
        'reassigned_to_seller_id' => 'integer',
        'reassigned_at' => 'datetime',
        'reassignment_audit_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity_snapshot' => 'integer',
        'item_net_amount' => 'integer',
        'commission_amount' => 'integer',
        'missing_rate' => 'boolean',
        'calculation_version' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), self::STATUS_ACTIVE)
            ->whereNotNull($query->qualifyColumn('active_invoice_id'));
    }

    public function scopeReassigned(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_REASSIGNED);
    }

    public function document()
    {
        return $this->belongsTo(SellerSalesDocument::class, 'seller_sales_document_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function activeInvoice()
    {
        return $this->belongsTo(Invoice::class, 'active_invoice_id');
    }

    public function reassignedToSeller()
    {
        return $this->belongsTo(User::class, 'reassigned_to_seller_id');
    }

    public function reassignmentAudit()
    {
        return $this->belongsTo(SellerReassignmentAudit::class, 'reassignment_audit_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
