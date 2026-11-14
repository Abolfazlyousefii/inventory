<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAccountDocument extends Model
{
    protected $fillable = ['customer_id', 'supplier_id', 'type', 'amount', 'title', 'description', 'document_date', 'created_by', 'submission_token', 'version', 'cancelled_at'];

    protected $casts = ['amount' => 'integer', 'document_date' => 'date', 'version' => 'integer', 'cancelled_at' => 'datetime'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
