<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BundleAssembly extends Model
{
    protected $fillable = [
        'bundle_variant_id', 'warehouse_id', 'performed_by',
        'operation', 'quantity', 'components_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'components_snapshot' => 'array',
    ];

    public function bundleVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'bundle_variant_id');
    }
}
