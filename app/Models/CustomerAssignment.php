<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAssignment extends Model
{
    protected $fillable = ['customer_id', 'seller_id', 'version'];

    protected $casts = ['version' => 'integer', 'seller_id' => 'integer'];

    public function seller()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
