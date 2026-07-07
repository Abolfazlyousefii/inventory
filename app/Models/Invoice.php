<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = [
        'uuid','customer_id','preinvoice_order_id',
        'customer_name','customer_mobile','customer_address',
        'province_id','city_id','shipping_id','shipping_price',
        'discount_amount','subtotal','total','status','status_changed_at','status_changed_by'
        ,'shipping_status','shipped_at','shipped_by','shipping_note',
        'external_order_id', 'items_updated_at', 'items_updated_by'
    ];

    protected $casts = [
        'shipping_status' => 'string',
        'shipped_at' => 'datetime',
        'shipped_by' => 'integer',
        'shipping_note' => 'string',
    ];

    public function items() { return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function payments() { return $this->hasMany(InvoicePayment::class); }
    public function notes() { return $this->hasMany(InvoiceNote::class)->latest(); }
    public function attachments() { return $this->hasMany(InvoiceAttachment::class)->latest(); }
    public function preinvoiceOrder() { return $this->belongsTo(PreinvoiceOrder::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function shippingMethod() { return $this->belongsTo(ShippingMethod::class, 'shipping_id'); }
    public function statusChangedByUser() { return $this->belongsTo(User::class, 'status_changed_by'); }
    public function shippedBy() { return $this->belongsTo(User::class, 'shipped_by'); }
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
