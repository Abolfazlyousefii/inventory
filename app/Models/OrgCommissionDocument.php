<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionDocument extends Model
{
    protected $table = 'org_commission_documents';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'uuid', 'document_number', 'period_from', 'period_to',
        'total_seller_commission', 'total_allocated', 'notes', 'status',
        'created_by', 'updated_by', 'confirmed_by', 'confirmed_at',
        'finalized_by', 'finalized_at',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'total_seller_commission' => 'integer',
        'total_allocated' => 'integer',
        'confirmed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

    public function allocations()
    {
        return $this->hasMany(OrgCommissionAllocation::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
