<?php

namespace App\Services;

use App\Models\{Customer, User};
use App\Services\Sms\SmsManager;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

/** Purpose-specific approval delivery; never issues login credentials or sessions. */
class CustomerSaleApprovalMessenger
{
    public function __construct(private readonly SmsManager $sms) {}

    public function send(User $owner, User $requester, Customer $customer, string $code): void
    {
        if (config('sms.default') === 'log') {
            // Do not persist the OTP in plaintext, even in the local SMS preview log.
            Log::info('Customer sale approval delivery simulated.', ['owner_id' => $owner->id, 'requester_id' => $requester->id, 'customer_id' => $customer->id]);
            return;
        }
        $phone = PhoneNormalizer::normalize((string) $owner->phone);
        $number = $customer->crm_customer_id ?: $customer->id;
        $pattern = (string) config('sales-approval.sms_pattern', '');
        if ($pattern !== '') {
            $this->sms->sendPattern($phone, $pattern, [$requester->name, (string) $number, $code]);
            return;
        }
        $this->sms->send($phone, "آریا گستر؛ درخواست ثبت یک پیش‌فاکتور توسط {$requester->name} برای مشتری {$number}. فقط در صورت موافقت، کد {$code} را به همکار بدهید. اعتبار ۵ دقیقه. این کد برای ورود نیست.");
    }
}
