<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelList extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand',
        'model_name',
        'code',
    ];

    public function productVariants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}
