<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerSalesDocument extends Model
{
    // === New constants ===
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'uuid', 'document_number', 'seller_id',
        'period_from', 'period_to',
        'invoice_count', 'total_sales_amount',
        // === New commission fields ===
        'total_commission_amount', 'total_adjustment_amount',
        'bonus_amount', 'bonus_reason',
        'cash_collected_amount', 'net_commission_amount',
        'status', 'confirmed_by', 'confirmed_at',
        'finalized_by', 'finalized_at', 'missing_rate_count',
        // === End new fields ===
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'invoice_count' => 'integer',
        'total_sales_amount' => 'integer',
        'total_commission_amount' => 'integer',
        'total_adjustment_amount' => 'integer',
        'bonus_amount' => 'integer',
        'cash_collected_amount' => 'integer',
        'net_commission_amount' => 'integer',
        'missing_rate_count' => 'integer',
        'confirmed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items()
    {
        return $this->hasMany(SellerSalesDocumentItem::class);
    }

    public function activeItems()
    {
        return $this->items()->active();
    }

    public function reassignedItems()
    {
        return $this->items()->reassigned();
    }

    public function adjustments()
    {
        return $this->hasMany(SellerSalesDocumentAdjustment::class);
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
