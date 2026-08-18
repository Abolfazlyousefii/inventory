<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerReassignmentAudit extends Model
{
    protected $guarded = [];
    protected $casts = ['changed_at' => 'datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function oldSeller()
    {
        return $this->belongsTo(User::class, 'old_seller_id');
    }

    public function newSeller()
    {
        return $this->belongsTo(User::class, 'new_seller_id');
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function releasedCommissionItems()
    {
        return $this->hasMany(SellerSalesDocumentItem::class, 'reassignment_audit_id');
    }
}
