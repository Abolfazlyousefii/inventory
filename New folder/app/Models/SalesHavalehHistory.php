<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesHavalehHistory extends Model
{
    protected $table = 'sales_havaleh_histories';

    protected $fillable = [
        'invoice_id',
        'action_type',
        'field_name',
        'old_value',
        'new_value',
        'description',
        'done_by',
        'done_at',
    ];

    protected $casts = [
        'done_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
