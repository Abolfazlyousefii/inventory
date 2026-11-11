<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAccountTransaction extends Model
{
    protected $fillable = [
        'supplier_id',
        'direction',
        'amount',
        'transaction_date',
        'method',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transaction_date' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
