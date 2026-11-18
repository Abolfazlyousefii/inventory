<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaeidianChequeEntry extends Model
{
    protected $fillable = ['kind', 'cheque_id', 'amount', 'effective_date', 'cheque_number', 'party', 'notes', 'created_by'];

    protected $casts = ['amount' => 'integer', 'effective_date' => 'date'];
}
