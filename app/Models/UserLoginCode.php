<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLoginCode extends Model
{
    protected $fillable = ['user_id', 'phone', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer']; }
}
