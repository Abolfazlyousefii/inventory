<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceNote extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'body',
    ];

    // اگر timestamps داری (پیش‌فرض true هست) لازم نیست چیزی بنویسی

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
