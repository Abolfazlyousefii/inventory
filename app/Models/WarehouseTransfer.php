<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseTransfer extends Model
{
    public const TYPE_SALE = 'sale';
    public const TYPE_BETWEEN_WAREHOUSES = 'between_warehouses';
    public const TYPE_SCRAP = 'scrap';
    public const TYPE_CUSTOMER_RETURN = 'customer_return';
    public const TYPE_SHOWROOM = 'showroom';
    public const TYPE_PERSONNEL_ASSET = 'personnel_asset';
    public const RETURN_SOURCE_INTERNAL_INVOICE = 'internal_invoice';
    public const RETURN_SOURCE_EXTERNAL_MANUAL = 'external_manual';
    public const RETURN_REASON_WRONG_PICKING = 'wrong_picking';
    public const RETURN_REASON_WARRANTY = 'warranty';
    public const RETURN_REASON_PACKAGING_DAMAGE = 'packaging_damage';
    public const RETURN_REASON_TRANSIT_DAMAGE = 'transit_damage';
    public const RETURN_REASON_GOODS_HEALTHY = 'goods_healthy';

    public static function typeOptions(): array
    {
        return [
            self::TYPE_BETWEEN_WAREHOUSES => 'حواله بین انبار',
            self::TYPE_SCRAP => 'حواله ضایعات',
            self::TYPE_CUSTOMER_RETURN => 'حواله مرجوعی مشتری',
            self::TYPE_SHOWROOM => 'حواله شوروم سالن',
            self::TYPE_PERSONNEL_ASSET => 'حواله اموال پرسنل',
        ];
    }

    public static function returnSourceOptions(): array
    {
        return [
            self::RETURN_SOURCE_INTERNAL_INVOICE => 'فاکتور داخلی',
            self::RETURN_SOURCE_EXTERNAL_MANUAL => 'فاکتور سازه‌حساب / دستی',
        ];
    }

    public static function returnSourceLabel(?string $source): string
    {
        return self::returnSourceOptions()[$source ?: self::RETURN_SOURCE_INTERNAL_INVOICE] ?? 'فاکتور داخلی';
    }

    public static function returnReasonOptions(): array
    {
        return [
            self::RETURN_REASON_WRONG_PICKING => 'اشتباه در جمع‌آوری سفارش',
            self::RETURN_REASON_WARRANTY => 'برگشت به دلیل گارانتی (خرابی کالا)',
            self::RETURN_REASON_PACKAGING_DAMAGE => 'آسیب‌دیدگی به‌دلیل پکینگ/کارتن',
            self::RETURN_REASON_TRANSIT_DAMAGE => 'شکستگی در مسیر ارسال',
            self::RETURN_REASON_GOODS_HEALTHY => 'سالم بودن کالا',
        ];
    }

    protected $fillable = [
        'reference',
        'voucher_type',
        'from_warehouse_id',
        'to_warehouse_id',
        'related_invoice_id',
        'return_type',
        'external_invoice_number',
        'customer_id',
        'beneficiary_name',
        'receiver_user_id',
        'receiver_name_snapshot',
        'return_reason',
        'user_id',
        'transferred_at',
        'total_amount',
        'note',
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
    ];

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(WarehouseTransferItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receiverUser()
    {
        return $this->belongsTo(User::class, 'receiver_user_id');
    }

    public function receiverDisplayName(): string
    {
        return $this->receiver_name_snapshot
            ?: ($this->receiverUser?->name
                ?: ($this->beneficiary_name
                    ?: ($this->toWarehouse?->personnel_name
                        ?: ($this->toWarehouse?->name ?: '—'))));
    }

    public function relatedInvoice()
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function returnKindLabel(): string
    {
        $kinds = $this->items->map(fn ($item) => $item->effectiveReturnKind())->unique()->values();
        if ($kinds->contains('healthy') && $kinds->contains('damaged')) {
            return 'سالم و مرجوعی';
        }
        return $kinds->first() === 'damaged' ? 'مرجوعی' : 'سالم';
    }
}
