<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseInboundReceipt extends Model
{
    public const SOURCE_SALES_RETURN = 'sales_return';
    public const SOURCE_INVOICE_CANCEL = 'invoice_cancel';
    public const SOURCE_INVOICE_ADJUSTMENT = 'invoice_adjustment';
    public const SOURCE_FINANCE_ADJUSTMENT_LEGACY = 'finance_adjustment';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_DISCREPANCY = 'discrepancy';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'receipt_number', 'source_type', 'source_id', 'operation_key', 'source_number_snapshot', 'customer_name_snapshot', 'source_meta',
        'status', 'expected_quantity', 'accepted_quantity', 'requested_by', 'reviewed_by',
        'reviewed_at', 'cancelled_by', 'cancelled_at', 'request_note', 'review_note',
    ];

    protected $casts = [
        'source_meta' => 'array',
        'expected_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(WarehouseInboundReceiptItem::class, 'receipt_id')->orderBy('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_DISCREPANCY], true);
    }

    public function getDifferenceAttribute(): int
    {
        return (int) $this->accepted_quantity - (int) $this->expected_quantity;
    }

    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_SALES_RETURN => 'برگشت از فروش',
            self::SOURCE_INVOICE_CANCEL => 'لغو فاکتور',
            self::SOURCE_INVOICE_ADJUSTMENT => 'اصلاح / کاهش فاکتور',
            self::SOURCE_FINANCE_ADJUSTMENT_LEGACY => 'اصلاح / کاهش فاکتور',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'در انتظار دریافت',
            self::STATUS_RECEIVED => 'دریافت‌شده',
            self::STATUS_DISCREPANCY => 'دریافت با مغایرت',
            self::STATUS_CANCELLED => 'لغوشده',
        ];
    }
}
