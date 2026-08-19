<?php

namespace App\Actions\CustomerAuth;

use App\Contracts\OtpSender;
use App\Models\Customer;
use App\Models\CustomerLoginCode;
use Illuminate\Support\Facades\Hash;

class RequestCustomerOtpAction
{
    public function __construct(private readonly OtpSender $sender) {}

    public function execute(string $phone, ?string $ip = null): void
    {
        $customer = Customer::query()->active()
            ->whereHas('phones', fn ($query) => $query->where('phone', $phone))
            ->first();

        if ($customer === null) {
            return;
        }

        CustomerLoginCode::query()->where('customer_id', $customer->id)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        CustomerLoginCode::query()->create([
            'customer_id' => $customer->id,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('jahesh.otp.expires_minutes', 5)),
            'request_ip' => $ip,
        ]);
        $this->sender->send($phone, $code);
    }
}
