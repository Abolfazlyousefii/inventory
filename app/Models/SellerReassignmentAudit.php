<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerReassignmentAudit extends Model
{
    protected $guarded = [];
    protected $casts = ['changed_at' => 'datetime'];
}
