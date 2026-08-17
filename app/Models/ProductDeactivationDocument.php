<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDeactivationDocument extends Model
{
    public const ACTION_DEACTIVATE = 'deactivate';

    public const ACTION_ACTIVATE = 'activate';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_VARIANTS = 'variants';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_SUBCATEGORY = 'subcategory';

    public const SCOPE_MULTIPLE_PRODUCTS = 'multiple_products';

    public const PRODUCT_LEVEL_SCOPES = [
        self::SCOPE_PRODUCT,
        self::SCOPE_CATEGORY,
        self::SCOPE_SUBCATEGORY,
        self::SCOPE_MULTIPLE_PRODUCTS,
    ];

    public const TYPE_PRODUCT = 'product';

    public const TYPE_VARIANT = 'variant';

    public const TYPE_SUBCATEGORY = 'subcategory';

    public const TYPE_CATEGORY = 'category';

    protected $fillable = [
        'document_number',
        'action_type',
        'scope_type',
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
            self::TYPE_SUBCATEGORY => 'زیر‌دسته',
            self::TYPE_CATEGORY => 'دسته‌بندی',
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

    public static function actionLabels(): array
    {
        return [self::ACTION_DEACTIVATE => 'غیرفعال‌سازی', self::ACTION_ACTIVATE => 'فعال‌سازی'];
    }

    public static function activationReasonLabels(): array
    {
        return [
            'restocked' => 'تأمین مجدد',
            'supplier_resumed' => 'ازسرگیری همکاری تأمین‌کننده',
            'management_reactivation' => 'فعال‌سازی با تصمیم مدیریت',
            'issue_resolved' => 'رفع مشکل',
            'custom' => 'دلیل سفارشی',
        ];
    }
}
