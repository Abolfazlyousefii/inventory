<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionDocumentCorrection extends Model
{
    protected $guarded = [];

    protected $casts = ['base_amount' => 'integer', 'campaign_amount' => 'integer', 'total_amount' => 'integer', 'is_stale' => 'boolean', 'added_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(CommissionDocument::class, 'commission_document_id');
    }

    public function correction()
    {
        return $this->belongsTo(CommissionCorrectionEntry::class, 'commission_correction_entry_id');
    }
}
