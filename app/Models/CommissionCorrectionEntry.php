<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionCorrectionEntry extends Model
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PENDING_PERIOD = 'pending_unassigned_period';

    protected $guarded = [];

    protected $casts = ['quantity_delta' => 'integer', 'net_amount' => 'integer', 'base_commission_amount' => 'integer', 'campaign_commission_amount' => 'integer', 'total_commission_amount' => 'integer', 'metadata' => 'array'];

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function sourcePeriod()
    {
        return $this->belongsTo(CommissionPeriod::class, 'source_period_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sourceLedgerEntry()
    {
        return $this->belongsTo(CommissionLedgerEntry::class, 'source_ledger_entry_id');
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturnDocument::class, 'sales_return_document_id');
    }
}
