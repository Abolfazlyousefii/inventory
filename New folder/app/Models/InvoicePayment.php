<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
{
    protected $fillable = [
        'invoice_id',
        'customer_id',
        'created_by',
        'method',
        'amount',
        'paid_at',
        'bank_name',
        'payment_identifier',
        'receipt_image',
        'note',
    ];

    public function invoice(){ return $this->belongsTo(Invoice::class); }
    public function customer(){ return $this->belongsTo(Customer::class); }
    public function creator(){ return $this->belongsTo(User::class, 'created_by'); }
 

    public function cheque()
    {
        return $this->hasOne(Cheque::class, 'invoice_payment_id');
    }
}
