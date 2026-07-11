<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDeactivationDocument extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_VARIANT = 'variant';

    protected $fillable = [
        'document_number',
        'deactivation_type',
        'product_id',
        'variant_id',
        'items_count',
        'reason_type',
        'reason_text',
        'description',
        'product_name_snapshot',
        'variant_name_snapshot',
        'created_by',
    ];

    protected $casts = [
        'items_count' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(ProductDeactivationDocumentItem::class, 'document_id');
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_PRODUCT => 'محصول',
            self::TYPE_VARIANT => 'تنوع',
        ];
    }

    public static function reasonLabels(): array
    {
        return [
            'supplier_ended' => 'اتمام همکاری با تامین‌کننده',
            'sales_stopped' => 'توقف فروش',
            'quality_issue' => 'خرابی یا مشکل کیفیت',
            'long_term_out_of_stock' => 'عدم موجودی بلندمدت',
            'management_decision' => 'تصمیم مدیریتی',
            'wrong_registration' => 'اشتباه در ثبت',
            'custom' => 'دلیل سفارشی',
        ];
    }
}
