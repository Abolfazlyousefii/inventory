<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerSalesDocumentItem extends Model
{
    protected $fillable = ['seller_sales_document_id', 'invoice_id', 'invoice_number_snapshot', 'invoice_date_snapshot', 'customer_name_snapshot', 'invoice_total_snapshot'];

    protected $casts = ['invoice_date_snapshot' => 'datetime', 'invoice_total_snapshot' => 'integer'];

    public function document()
    {
        return $this->belongsTo(SellerSalesDocument::class, 'seller_sales_document_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
