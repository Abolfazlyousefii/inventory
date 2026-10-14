<?php

namespace App\Services\Sms\Drivers;

use App\Contracts\SmsDriver;
use Illuminate\Support\Facades\Log;

class LogDriver implements SmsDriver
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS preview', [
            'phone' => '[redacted]',
            'message' => $this->redactTrackingUrls($message),
        ]);
    }

    public function sendPattern(string $phone, string $pattern, array $parameters = []): void
    {
        Log::info('SMS pattern preview', [
            'phone' => '[redacted]',
            'pattern' => $pattern,
            'parameters' => array_map(fn ($parameter) => $this->redactTrackingUrls((string) $parameter), $parameters),
        ]);
    }

    private function redactTrackingUrls(string $text): string
    {
        return preg_replace('~https?://[^\s]+?/track/[^\s]+~u', '[customer tracking link redacted]', $text) ?? $text;
    }
}
