<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerSalesDocument extends Model
{
    protected $fillable = ['uuid', 'document_number', 'seller_id', 'period_from', 'period_to', 'invoice_count', 'total_sales_amount', 'notes', 'created_by', 'updated_by'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'invoice_count' => 'integer', 'total_sales_amount' => 'integer'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items()
    {
        return $this->hasMany(SellerSalesDocumentItem::class);
    }

    public function activeItems()
    {
        return $this->items()->active();
    }

    public function reassignedItems()
    {
        return $this->items()->reassigned();
    }
}
