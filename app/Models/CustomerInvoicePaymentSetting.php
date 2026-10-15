<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInvoicePaymentSetting extends Model
{
    protected $fillable = ['name', 'national_id', 'iban', 'account', 'card'];

    protected $hidden = ['name', 'national_id', 'iban', 'account', 'card'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'national_id' => 'encrypted',
            'iban' => 'encrypted',
            'account' => 'encrypted',
            'card' => 'encrypted',
        ];
    }
}
