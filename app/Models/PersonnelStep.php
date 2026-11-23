<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelStep extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['decided_at' => 'datetime'];

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
