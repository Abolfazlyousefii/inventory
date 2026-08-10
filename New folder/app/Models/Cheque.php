<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    protected $fillable = [
        'invoice_payment_id',
        'bank_name',
        'branch_name',
        'cheque_number',
        'amount',
        'due_date',
        'received_at',
        'customer_name',
        'customer_code',
        'account_number',
        'account_holder',
        'image',
        'status',
    ];

    protected $casts = [
        'amount' => 'integer',
        'due_date' => 'date',
        'received_at' => 'date',
    ];

    public function payment()
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }
}