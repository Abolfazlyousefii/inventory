<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerSalesDocumentAdjustment extends Model
{
    protected $fillable = [
        'seller_sales_document_id',
        'invoice_id',
        'amount',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'invoice_id' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(SellerSalesDocument::class, 'seller_sales_document_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
