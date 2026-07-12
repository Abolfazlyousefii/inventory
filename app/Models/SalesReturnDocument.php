<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturnDocument extends Model
{
    public const SOURCE_INTERNAL_INVOICE = 'internal_invoice';
    public const SOURCE_SAZEH_HESAB = 'sazeh_hesab';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'document_number', 'source_type', 'status', 'customer_id', 'invoice_id',
        'external_invoice_number', 'external_invoice_date', 'return_reason', 'description',
        'refund_subtotal', 'refund_total', 'items_count', 'created_by', 'updated_by',
        'applied_by', 'applied_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'external_invoice_date' => 'date',
        'refund_subtotal' => 'integer',
        'refund_total' => 'integer',
        'items_count' => 'integer',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function items(): HasMany { return $this->hasMany(SalesReturnDocumentItem::class, 'document_id')->orderBy('sort_order')->orderBy('id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isApplied(): bool { return $this->status === self::STATUS_APPLIED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function isInternal(): bool { return $this->source_type === self::SOURCE_INTERNAL_INVOICE; }
    public function isSazehHesab(): bool { return $this->source_type === self::SOURCE_SAZEH_HESAB; }

    public static function sourceTypeLabels(): array
    {
        return [
            self::SOURCE_INTERNAL_INVOICE => 'برگشت داخلی',
            self::SOURCE_SAZEH_HESAB => 'برگشت سازه‌حساب',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'پیش‌نویس',
            self::STATUS_APPLIED => 'اعمال‌شده',
            self::STATUS_CANCELLED => 'لغوشده',
        ];
    }

    public function sourceTypeLabel(): string { return self::sourceTypeLabels()[$this->source_type] ?? $this->source_type; }
    public function statusLabel(): string { return self::statusLabels()[$this->status] ?? $this->status; }
}
