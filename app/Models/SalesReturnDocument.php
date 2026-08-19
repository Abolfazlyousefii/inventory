<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnDocument extends Model
{
    public const SOURCE_INTERNAL_INVOICE = 'internal_invoice';

    public const SOURCE_SAZEH_HESAB = 'sazeh_hesab';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PENDING_WAREHOUSE = 'pending_warehouse';

    public const STATUS_CANCELLED = 'cancelled';

    public const COMMISSION_COMMERCIAL = 'commercial';

    public const COMMISSION_WARRANTY = 'warranty';

    public const COMMISSION_SERVICE = 'service';

    public const COMMISSION_REPLACEMENT = 'replacement';

    protected $fillable = ['document_number', 'source_type', 'status', 'customer_id', 'invoice_id', 'external_invoice_number', 'external_invoice_date', 'default_destination_warehouse_id', 'return_reason', 'commission_effect_type', 'reference_number', 'description', 'total_quantity', 'items_count', 'total_refund_amount', 'created_by', 'updated_by', 'applied_by', 'applied_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'];

    protected $casts = ['external_invoice_date' => 'date', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime', 'total_quantity' => 'integer', 'items_count' => 'integer', 'total_refund_amount' => 'integer'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items()
    {
        return $this->hasMany(SalesReturnDocumentItem::class, 'document_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function defaultDestinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'default_destination_warehouse_id');
    }

    public function revisions()
    {
        return $this->hasMany(SalesReturnDocumentRevision::class, 'document_id')->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function isPendingWarehouse(): bool
    {
        return $this->status === self::STATUS_PENDING_WAREHOUSE;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isInternal(): bool
    {
        return $this->source_type === self::SOURCE_INTERNAL_INVOICE;
    }

    public function isSazehHesab(): bool
    {
        return $this->source_type === self::SOURCE_SAZEH_HESAB;
    }

    public static function statusLabels(): array
    {
        return [self::STATUS_DRAFT => 'پیش‌نویس', self::STATUS_PENDING_WAREHOUSE => 'در انتظار دریافت انبار', self::STATUS_APPLIED => 'ثبت نهایی', self::STATUS_CANCELLED => 'لغوشده'];
    }

    public static function sourceTypeLabels(): array
    {
        return [self::SOURCE_INTERNAL_INVOICE => 'فاکتور داخلی', self::SOURCE_SAZEH_HESAB => 'فاکتور سازه‌حساب'];
    }

    public static function returnReasonLabels(): array
    {
        return ['healthy_return' => 'برگشت سالم / بدون ایراد', 'damaged_product' => 'خرابی کالا', 'product_mismatch' => 'مغایرت کالا', 'wrong_dispatch' => 'اشتباه در ارسال', 'customer_cancellation' => 'انصراف مشتری', 'appearance_issue' => 'ایراد ظاهری', 'technical_issue' => 'ایراد فنی', 'registration_error' => 'ثبت اشتباه', 'other' => 'سایر'];
    }

    public static function commissionEffectLabels(): array
    {
        return [self::COMMISSION_COMMERCIAL => 'برگشت تجاری (کسر پورسانت)', self::COMMISSION_WARRANTY => 'گارانتی (بدون اثر)', self::COMMISSION_SERVICE => 'خدمات (بدون اثر)', self::COMMISSION_REPLACEMENT => 'تعویض (بدون اثر)'];
    }
}
