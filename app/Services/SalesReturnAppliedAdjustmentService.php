<?php

namespace App\Services;

use App\Models\SalesReturnDocument;
use Illuminate\Validation\ValidationException;

class SalesReturnAppliedAdjustmentService
{
    public function updateApplied(SalesReturnDocument $document, array $data, int $actorId, string $reason): SalesReturnDocument
    {
        throw ValidationException::withMessages(['document' => 'این مسیر قدیمی ویرایش غیرفعال است؛ از ویرایش مرجوعی یا اصلاح دریافت توسط مسئول انبار استفاده کنید.']);
    }

    public function voidApplied(SalesReturnDocument $document, int $actorId, string $reason): SalesReturnDocument
    {
        throw ValidationException::withMessages(['document' => 'این مسیر قدیمی ابطال غیرفعال است؛ از ابطال نسخه‌دار سند با کنترل سابقه و موجودی آزاد استفاده کنید.']);
    }
}
