<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
