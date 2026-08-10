<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountDocument extends Model
{
    protected $fillable = [
        'document_number',
        'type',
        'warehouse_id',
        'product_id',
        'document_date',
        'status',
        'description',
        'variants_count',
        'counted_count',
        'zeroed_count',
        'increased_count',
        'decreased_count',
        'total_before',
        'total_actual',
        'total_increase',
        'total_decrease',
        'finalized_by',
        'finalized_at',
        'created_by',
        'updated_by',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'document_date' => 'date',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function items()
    {
        return $this->hasMany(StockCountDocumentItem::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function history()
    {
        return $this->hasMany(StockCountDocumentHistory::class, 'document_id');
    }
}
