<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFollowup extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['customer_id' => 'integer', 'assigned_to' => 'integer', 'interaction_id' => 'integer', 'version' => 'integer', 'due_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function interaction()
    {
        return $this->belongsTo(CustomerInteraction::class, 'interaction_id');
    }

    public static function statuses(): array
    {
        return ['open' => 'باز', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];
    }
}
