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
    ];

    protected $casts = [
        'invoice_date_snapshot' => 'datetime',
        'invoice_total_snapshot' => 'integer',
        'active_invoice_id' => 'integer',
        'reassigned_to_seller_id' => 'integer',
        'reassigned_at' => 'datetime',
        'reassignment_audit_id' => 'integer',
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
}
