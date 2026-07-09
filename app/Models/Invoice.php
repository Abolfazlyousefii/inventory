<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Invoice extends Model
{
    public const STATUS_PENDING_WAREHOUSE_APPROVAL = 'pending_warehouse_approval';
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_CHECKING_DISCREPANCY = 'checking_discrepancy';
    public const STATUS_FINAL_CHECK = 'final_check';
    public const STATUS_PACKING = 'packing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_NOT_SHIPPED = 'not_shipped';
    public const STATUS_PENDING_COLLECTION = 'pending_collection';
    public const STATUS_WAREHOUSE_RECEIVED = 'warehouse_received';
    public const STATUS_PENDING_FINANCE_REAPPROVAL = 'pending_finance_reapproval';
    public const STATUS_READY_TO_SHIP = 'ready_to_ship';
    public const STATUS_RETURNED_TO_SALES_AFTER_COLLECTION = 'returned_to_sales_after_collection';

    protected $fillable = [
        'uuid','customer_id','preinvoice_order_id',
        'customer_name','customer_mobile','customer_address',
        'province_id','city_id','shipping_id','shipping_price',
        'shipping_method_id','shipping_cost',
        'discount_amount','subtotal','total','status','status_changed_at','status_changed_by'
        ,'shipping_status','shipped_at','shipped_by','shipping_note',
        'external_order_id', 'items_updated_at', 'items_updated_by',
        'warehouse_received_at', 'warehouse_received_by', 'collection_started_at',
        'collection_started_by', 'collected_at', 'collected_by', 'collection_note'
    ];

    protected $casts = [
        'shipping_status' => 'string',
        'shipping_cost' => 'integer',
        'shipped_at' => 'datetime',
        'shipped_by' => 'integer',
        'shipping_note' => 'string',
        'warehouse_received_at' => 'datetime',
        'warehouse_received_by' => 'integer',
        'collection_started_at' => 'datetime',
        'collection_started_by' => 'integer',
        'collected_at' => 'datetime',
        'collected_by' => 'integer',
        'collection_note' => 'string',
        'items_updated_at' => 'datetime',
        'items_updated_by' => 'integer',
    ];


    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->exists && $invoice->isDirty('uuid')) {
                Log::warning('Blocked attempt to change immutable invoice number.', [
                    'invoice_id' => $invoice->id,
                    'original_uuid' => $invoice->getOriginal('uuid'),
                    'attempted_uuid' => $invoice->uuid,
                ]);

                throw ValidationException::withMessages([
                    'uuid' => 'شماره فاکتور پس از صدور قابل تغییر نیست.',
                ]);
            }
        });
    }

    public function recalculateSnapshotTotals(): void
    {
        $this->loadMissing('items');

        foreach ($this->items as $item) {
            $item->assertValidSnapshotPrice();
        }

        $subtotal = (int) $this->items->sum(fn (InvoiceItem $item) => (int) $item->quantity * (int) $item->price);
        $discount = (int) $this->items->sum(fn (InvoiceItem $item) => (int) ($item->line_discount_amount ?? 0));
        $discount = max($discount, (int) ($this->discount_amount ?? 0));

        $this->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => max($subtotal - $discount, 0),
        ])->save();
    }

    public function hasZeroPriceItems(): bool
    {
        return $this->items->contains(fn (InvoiceItem $item) => (int) $item->quantity > 0 && (int) $item->price <= 0);
    }

    public function hasTotalMismatch(): bool
    {
        $this->loadMissing('items');
        $subtotal = (int) $this->items->sum(fn (InvoiceItem $item) => (int) $item->quantity * (int) $item->price);
        $discount = max((int) ($this->discount_amount ?? 0), (int) $this->items->sum(fn (InvoiceItem $item) => (int) ($item->line_discount_amount ?? 0)));

        return (int) $this->subtotal !== $subtotal || (int) $this->total !== max($subtotal - $discount, 0);
    }

    public function items() { return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function payments() { return $this->hasMany(InvoicePayment::class); }
    public function notes() { return $this->hasMany(InvoiceNote::class)->latest(); }
    public function attachments() { return $this->hasMany(InvoiceAttachment::class)->latest(); }
    public function preinvoiceOrder() { return $this->belongsTo(PreinvoiceOrder::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function shippingMethod() { return $this->belongsTo(ShippingMethod::class, 'shipping_id'); }
    public function dispatchShippingMethod() { return $this->belongsTo(ShippingMethod::class, 'shipping_method_id'); }
    public function statusChangedByUser() { return $this->belongsTo(User::class, 'status_changed_by'); }
    public function shippedBy() { return $this->belongsTo(User::class, 'shipped_by'); }
    public function warehouseReceivedBy() { return $this->belongsTo(User::class, 'warehouse_received_by'); }
    public function collectionStartedBy() { return $this->belongsTo(User::class, 'collection_started_by'); }
    public function collectedBy() { return $this->belongsTo(User::class, 'collected_by'); }
    public function histories() { return $this->hasMany(SalesHavalehHistory::class)->latest('done_at'); }
    public function activityLogs() { return $this->morphMany(ActivityLog::class, 'subject')->latest('occurred_at'); }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING_WAREHOUSE_APPROVAL => 'در انتظار تایید انبار',
            self::STATUS_COLLECTING => 'در حال جمع‌آوری',
            self::STATUS_CHECKING_DISCREPANCY => 'در حال مغایرت و بررسی',
            self::STATUS_FINAL_CHECK => 'در حال چک نهایی',
            self::STATUS_PACKING => 'در حال بسته‌بندی',
            self::STATUS_SHIPPED => 'ارسال شده',
            self::STATUS_NOT_SHIPPED => 'کنسل شده',
            self::STATUS_PENDING_COLLECTION => 'در صف جمع‌آوری',
            self::STATUS_WAREHOUSE_RECEIVED => 'دریافت‌شده توسط انبار',
            self::STATUS_PENDING_FINANCE_REAPPROVAL => 'در انتظار تایید مجدد مالی',
            self::STATUS_READY_TO_SHIP => 'آماده ارسال',
            self::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'ارجاع‌شده به اپراتور پس از اصلاح انبار',
        ];
    }

    public function getPaidAmountAttribute(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(((int)$this->total) - (int)$this->paid_amount, 0);
    }
}
