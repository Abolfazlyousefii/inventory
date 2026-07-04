<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PreinvoiceOrder extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (! $order->document_date) {
                $order->document_date = $order->created_at ?? now();
            }
        });
    }

    public function getDisplayDocumentDateAttribute()
    {
        return $this->document_date ?? $this->created_at;
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RESERVED_WAITING_WAREHOUSE = 'reserved_waiting_warehouse';
    public const STATUS_WAREHOUSE_REVIEWING = 'warehouse_reviewing';
    public const STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE = 'warehouse_approved_waiting_finance';
    public const STATUS_FINANCE_REVIEWING = 'finance_reviewing';
    public const STATUS_CONVERTED_TO_INVOICE = 'converted_to_invoice';
    public const STATUS_CANCELLED_BY_WAREHOUSE = 'cancelled_by_warehouse';
    public const STATUS_CANCELLED_BY_FINANCE = 'cancelled_by_finance';
    public const STATUS_RETURNED_TO_WAREHOUSE = 'returned_to_warehouse';

    protected $fillable = [
        'uuid',
        'external_order_id',
        'created_by',
        'document_date',
        'status',
        'customer_id', // <-- این فیلد اضافه شد تا باگ ذخیره نشدن مشتری رفع شود
        'customer_name',
        'customer_mobile',
        'customer_address',
        'description',
        'province_id',
        'city_id',
        'shipping_id',
        'shipping_price',
        'discount_amount',
        'discount_breakdown',
        'invoice_discount_type',
        'invoice_discount_value',
        'invoice_discount_amount',
        'product_discount_amount',
        'discount_allocation_mode',
        'total_price',
        'warehouse_review_note',
        'warehouse_reject_reason',
        'warehouse_reviewed_by',
        'warehouse_reviewed_at',
        'stock_frozen_until',
        'stock_released_at',
        'items_updated_at',
        'items_updated_by',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'external_order_id' => 'integer',
        'province_id' => 'integer',
        'city_id' => 'integer',
        'shipping_id' => 'integer',
        'shipping_price' => 'integer',
        'discount_amount' => 'integer',
        'discount_breakdown' => 'array',
        'invoice_discount_value' => 'integer',
        'invoice_discount_amount' => 'integer',
        'product_discount_amount' => 'integer',
        'total_price' => 'integer',
        'warehouse_reviewed_by' => 'integer',
        'warehouse_reviewed_at' => 'datetime',
        'stock_frozen_until' => 'datetime',
        'stock_released_at' => 'datetime',
        'items_updated_at' => 'datetime',
        'items_updated_by' => 'integer',
        'document_date' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PreinvoiceOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warehouseReviewer()
    {
        return $this->belongsTo(User::class, 'warehouse_reviewed_by');
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviews()
    {
        return $this->hasMany(PreinvoiceOrderReview::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'preinvoice_order_id');
    }

    public function warehouseReviewSnapshots()
    {
        return $this->hasMany(WarehouseReviewSnapshot::class);
    }

    public function warehouseReviewLogs()
    {
        return $this->hasMany(WarehouseReviewLog::class);
    }

    public function warehouseReviewItemLogs()
    {
        return $this->hasMany(WarehouseReviewItemLog::class);
    }

    public function activityLogs()
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('occurred_at');
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'ثبت شده / اولیه',
            self::STATUS_RESERVED_WAITING_WAREHOUSE => 'رزرو شده و در انتظار تایید انبار',
            self::STATUS_WAREHOUSE_REVIEWING => 'در حال بررسی توسط انبار',
            self::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE => 'تایید انبار و در انتظار مالی',
            self::STATUS_FINANCE_REVIEWING => 'در حال بررسی توسط مالی',
            self::STATUS_CONVERTED_TO_INVOICE => 'تبدیل‌شده به فاکتور',
            self::STATUS_CANCELLED_BY_WAREHOUSE => 'لغوشده توسط انبار',
            self::STATUS_CANCELLED_BY_FINANCE => 'لغوشده توسط مالی',
            self::STATUS_RETURNED_TO_WAREHOUSE => 'برگشت‌خورده از مالی به انبار',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }
}
