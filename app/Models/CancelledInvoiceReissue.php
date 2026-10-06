<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancelledInvoiceReissue extends Model
{
    protected $fillable = [
        'original_invoice_id',
        'replacement_preinvoice_order_id',
        'note',
    ];

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function replacementPreinvoice(): BelongsTo
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'replacement_preinvoice_order_id');
    }
}
