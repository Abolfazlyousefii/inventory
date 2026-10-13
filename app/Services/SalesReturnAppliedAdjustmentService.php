<?php

namespace App\Services;

use App\Models\SalesReturnDocument;
use Illuminate\Validation\ValidationException;

class SalesReturnAppliedAdjustmentService
{
    public function updateApplied(SalesReturnDocument $document, array $data, int $actorId, string $reason): SalesReturnDocument
    {
        throw ValidationException::withMessages(['document' => 'سند دریافت‌شده قابل ویرایش مستقیم نیست؛ درخواست اصلاح با تأیید انبار و مالی ثبت کنید.']);
    }

    public function voidApplied(SalesReturnDocument $document, int $actorId, string $reason): SalesReturnDocument
    {
        throw ValidationException::withMessages(['document' => 'سند دریافت‌شده قابل ابطال مستقیم نیست؛ درخواست اصلاح با تأیید انبار و مالی ثبت کنید.']);
    }
}
