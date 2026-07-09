<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceChangeDocument extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERTED = 'reverted';

    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_VARIANT = 'variant';
    public const SCOPE_MANUAL = 'manual';

    public const CHANGE_INCREASE_PERCENT = 'increase_percent';
    public const CHANGE_DECREASE_PERCENT = 'decrease_percent';
    public const CHANGE_INCREASE_AMOUNT = 'increase_amount';
    public const CHANGE_DECREASE_AMOUNT = 'decrease_amount';
    public const CHANGE_SET_FIXED_PRICE = 'set_fixed_price';

    public const ROUND_NONE = 'none';
    public const ROUND_1000 = 'round_1000';
    public const ROUND_5000 = 'round_5000';
    public const ROUND_10000 = 'round_10000';
    public const ROUND_50000 = 'round_50000';

    protected $fillable = ['uuid','code','title','scope_type','scope_payload','change_type','change_value','rounding_mode','status','items_count','created_by','applied_by','applied_at','cancelled_by','cancelled_at','reverted_by','reverted_at','note'];

    protected $casts = ['scope_payload' => 'array', 'change_value' => 'decimal:2', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime', 'reverted_at' => 'datetime'];

    public function items(): HasMany { return $this->hasMany(PriceChangeDocumentItem::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function appliedBy(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function revertedBy(): BelongsTo { return $this->belongsTo(User::class, 'reverted_by'); }

    public static function statusLabels(): array { return [self::STATUS_DRAFT => 'پیش‌نویس', self::STATUS_APPLIED => 'اعمال‌شده', self::STATUS_CANCELLED => 'لغوشده', self::STATUS_REVERTED => 'برگشت‌خورده']; }
    public static function scopeLabels(): array { return [self::SCOPE_CATEGORY => 'دسته‌بندی', self::SCOPE_PRODUCT => 'محصول', self::SCOPE_VARIANT => 'تنوع خاص', self::SCOPE_MANUAL => 'انتخاب دستی']; }
    public static function changeTypeLabels(): array { return [self::CHANGE_INCREASE_PERCENT => 'افزایش درصدی', self::CHANGE_DECREASE_PERCENT => 'کاهش درصدی', self::CHANGE_INCREASE_AMOUNT => 'افزایش مبلغ ثابت', self::CHANGE_DECREASE_AMOUNT => 'کاهش مبلغ ثابت', self::CHANGE_SET_FIXED_PRICE => 'قیمت ثابت جدید']; }
    public static function roundingLabels(): array { return [self::ROUND_NONE => 'بدون گرد کردن', self::ROUND_1000 => 'نزدیک‌ترین ۱٬۰۰۰', self::ROUND_5000 => 'نزدیک‌ترین ۵٬۰۰۰', self::ROUND_10000 => 'نزدیک‌ترین ۱۰٬۰۰۰', self::ROUND_50000 => 'نزدیک‌ترین ۵۰٬۰۰۰']; }

    public function statusLabel(): string { return self::statusLabels()[$this->status] ?? $this->status; }
    public function scopeLabel(): string { return self::scopeLabels()[$this->scope_type] ?? $this->scope_type; }
    public function changeTypeLabel(): string { return self::changeTypeLabels()[$this->change_type] ?? $this->change_type; }
    public function roundingLabel(): string { return self::roundingLabels()[$this->rounding_mode] ?? $this->rounding_mode; }
}
