<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionDocumentItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REMOVED = 'removed';

    protected $guarded = [];

    protected $casts = [
        'invoice_date_snapshot' => 'datetime', 'net_sales_snapshot' => 'integer',
        'base_commission_snapshot' => 'integer', 'campaign_commission_snapshot' => 'integer',
        'total_commission_snapshot' => 'integer', 'ledger_entry_count' => 'integer',
        'calculation_version' => 'integer', 'is_outside_period' => 'boolean', 'is_stale' => 'boolean',
        'added_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'removed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(CommissionDocument::class, 'commission_document_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sourcePeriod()
    {
        return $this->belongsTo(CommissionPeriod::class, 'source_period_id');
    }
}
