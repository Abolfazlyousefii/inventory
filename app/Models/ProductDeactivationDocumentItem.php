<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDeactivationDocumentItem extends Model
{
    protected $fillable = [
        'document_id',
        'action_type',
        'scope_type',
        'category_id',
        'subcategory_id',
        'product_id',
        'variant_id',
        'deactivation_type',
        'deactivation_status',
        'previous_sales_enabled',
        'new_sales_enabled',
        'category_name_snapshot',
        'subcategory_name_snapshot',
        'product_name_snapshot',
        'variant_name_snapshot',
    ];

    protected $casts = ['previous_sales_enabled' => 'boolean', 'new_sales_enabled' => 'boolean'];

    public function document()
    {
        return $this->belongsTo(ProductDeactivationDocument::class, 'document_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
