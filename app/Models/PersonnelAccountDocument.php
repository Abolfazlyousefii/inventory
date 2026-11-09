<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelAccountDocument extends Model
{
    protected $fillable = ['customer_id', 'type', 'amount', 'document_date', 'description', 'reference', 'created_by', 'submission_token'];

    protected $casts = ['amount' => 'integer', 'document_date' => 'date'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
