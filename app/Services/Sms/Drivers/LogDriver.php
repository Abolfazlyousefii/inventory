<?php

namespace App\Services\Sms\Drivers;

use App\Contracts\SmsDriver;
use Illuminate\Support\Facades\Log;

class LogDriver implements SmsDriver
{
        public function send(string $phone, string $message): void
    {
                Log::info('SMS preview', ['phone' => $phone, 'message' => $message]);
            }

    public function sendPattern(string $phone, string $pattern, array $parameters = []): void
    {
                Log::info('SMS pattern preview', ['phone' => $phone, 'pattern' => $pattern, 'parameters' => $parameters]);
            }
}