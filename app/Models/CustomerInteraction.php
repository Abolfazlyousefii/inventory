<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInteraction extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_hash', 'request_key'];

    protected $casts = ['customer_id' => 'integer', 'author_id' => 'integer'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function followup()
    {
        return $this->hasOne(CustomerFollowup::class, 'interaction_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public static function kinds(): array
    {
        return ['call' => 'تماس', 'message' => 'پیام', 'visit' => 'مراجعه', 'note' => 'یادداشت'];
    }
}
