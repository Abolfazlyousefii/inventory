<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionDocumentAdjustment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    protected $casts = ['amount_snapshot' => 'integer', 'is_stale' => 'boolean', 'added_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(CommissionDocument::class, 'commission_document_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(CommissionAdjustment::class, 'commission_adjustment_id');
    }
}
