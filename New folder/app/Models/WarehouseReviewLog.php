<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseReviewLog extends Model
{
    public const ACTION_ENTERED_QUEUE = 'entered_warehouse_queue';
    public const ACTION_ITEM_QUANTITY_CHANGED = 'item_quantity_changed';
    public const ACTION_ITEM_REMOVED = 'item_removed';
    public const ACTION_APPROVED_TO_FINANCE = 'approved_to_finance';
    public const ACTION_REJECTED_TO_CREATOR = 'rejected_to_creator';
    public const ACTION_RESUBMITTED_TO_WAREHOUSE = 'resubmitted_to_warehouse';
    public const ACTION_NOTE_ADDED = 'note_added';
    public const ACTION_SNAPSHOT_CREATED = 'snapshot_created';
    public const ACTION_CHANGES_SAVED = 'warehouse_changes_saved';

    protected $fillable = [
        'preinvoice_order_id',
        'user_id',
        'action',
        'status_from',
        'status_to',
        'note',
        'meta',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'meta' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
