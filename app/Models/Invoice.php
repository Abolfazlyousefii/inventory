<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Invoice extends Model
{
    public const FINANCIAL_LOCK_MESSAGE = 'فاکتور ارسال‌شده نهایی شده و امکان تغییر شماره، اقلام، قیمت، تخفیف یا مبلغ آن وجود ندارد. اصلاح این سند باید از طریق فرآیند رسمی اصلاحیه مالی انجام شود.';

    public const STATUS_PENDING_WAREHOUSE_APPROVAL = 'pending_warehouse_approval';
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_CHECKING_DISCREPANCY = 'checking_discrepancy';
    public const STATUS_FINAL_CHECK = 'final_check';
    public const STATUS_PACKING = 'packing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_NOT_SHIPPED = 'not_shipped';
    public const STATUS_CANCELED_LEGACY = 'canceled';
    public const STATUS_CANCELLED_LEGACY = 'cancelled';
    public const STATUS_PENDING_FINANCE_REAPPROVAL = 'pending_finance_reapproval';
    public const STATUS_FINANCE_APPROVED = 'finance_approved';
    public const COLLECTION_STATUS_COMPLETED = 'completed';

    public static function cancelledStatuses(): array
    {
        return [
            self::STATUS_NOT_SHIPPED,
            self::STATUS_CANCELED_LEGACY,
            self::STATUS_CANCELLED_LEGACY,
        ];
    }

    public function scopeNotCancelled($query)
    {
        return $query->whereNotIn('status', self::cancelledStatuses());
    }

    protected $fillable = [
        'uuid','customer_id','preinvoice_order_id','document_date',
        'customer_name','customer_mobile','customer_address',
        'province_id','city_id','shipping_id','shipping_price',
        'discount_amount','discount_breakdown','invoice_discount_type','invoice_discount_value',
        'invoice_discount_amount','product_discount_amount','discount_allocation_mode',
        'subtotal','total','status','status_changed_at','status_changed_by',
        'collection_status','collection_completed_at','collection_completed_by',
        'collection_transferred_to_warehouse_at','collection_transferred_to_warehouse_by',
        'external_order_id', 'items_updated_at', 'items_updated_by'
    ];

    protected $casts = [
        'document_date' => 'datetime',
        'status_changed_at' => 'datetime',
        'items_updated_at' => 'datetime',
        'collection_completed_at' => 'datetime',
        'collection_transferred_to_warehouse_at' => 'datetime',
        'discount_breakdown' => 'array',
        'invoice_discount_value' => 'integer',
        'invoice_discount_amount' => 'integer',
        'product_discount_amount' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (! $invoice->document_date) {
                $invoice->document_date = $invoice->preinvoiceOrder?->display_document_date ?? $invoice->created_at ?? now();
            }
        });

        static::updating(function (self $invoice): void {
            if ($invoice->isDirty('uuid')) {
                throw ValidationException::withMessages([
                    'uuid' => 'شماره فاکتور پس از ایجاد قابل تغییر نیست.',
                ]);
            }
        });
    }

    public function isFinanciallyLocked(): bool
    {
        return (string) $this->status === self::STATUS_SHIPPED;
    }

    public function assertFinanciallyMutable(): void
    {
        if ($this->isFinanciallyLocked()) {
            throw ValidationException::withMessages([
                'invoice' => self::FINANCIAL_LOCK_MESSAGE,
            ]);
        }
    }

    public function getDisplayDocumentDateAttribute()
    {
        return $this->document_date ?? $this->preinvoiceOrder?->display_document_date ?? $this->created_at;
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)
            ->orderByRaw('COALESCE(sort_order, id) ASC')
            ->orderBy('id', 'ASC');
    }
    public function payments() { return $this->hasMany(InvoicePayment::class); }
    public function notes() { return $this->hasMany(InvoiceNote::class)->latest(); }
    public function attachments() { return $this->hasMany(InvoiceAttachment::class)->latest(); }
    public function preinvoiceOrder() { return $this->belongsTo(PreinvoiceOrder::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function shippingMethod() { return $this->belongsTo(ShippingMethod::class, 'shipping_id'); }
    public function statusChangedByUser() { return $this->belongsTo(User::class, 'status_changed_by'); }
    public function histories() { return $this->hasMany(SalesHavalehHistory::class)->latest('done_at'); }
    public function activityLogs() { return $this->morphMany(ActivityLog::class, 'subject')->latest('occurred_at'); }

    public function getPaidAmountAttribute(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(((int)$this->total) - (int)$this->paid_amount, 0);
    }
}
