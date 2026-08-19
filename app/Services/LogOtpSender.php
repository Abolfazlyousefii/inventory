<?php

namespace App\Services;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Log;

class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        Log::info('Customer portal OTP generated', ['phone' => $phone, 'code' => $code]);
    }
}
