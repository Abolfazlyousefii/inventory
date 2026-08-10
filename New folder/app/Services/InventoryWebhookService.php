<?php

namespace App\Services;

use App\Models\InventoryWebhookLog;
use App\Models\InventoryWebhookSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class InventoryWebhookService
{
    public static function send(string $event, array $payload): void
    {
        self::processPending();

        if (!Schema::hasTable('inventory_webhook_settings') || !Schema::hasTable('inventory_webhook_logs')) {
            return;
        }

        $setting = InventoryWebhookSetting::query()->latest('id')->first();
        if (!$setting || !$setting->is_enabled || empty($setting->endpoint_url)) {
            return;
        }

        $log = InventoryWebhookLog::create([
            'setting_id' => $setting->id,
            'event' => $event,
            'target' => $setting->endpoint_url,
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
            'payload' => $payload,
        ]);

        self::dispatchLog($log, $setting);
    }

    public static function processPending(): void
    {
        if (!Schema::hasTable('inventory_webhook_settings') || !Schema::hasTable('inventory_webhook_logs')) {
            return;
        }

        $setting = InventoryWebhookSetting::query()->latest('id')->first();
        if (!$setting || !$setting->is_enabled || empty($setting->endpoint_url)) {
            return;
        }

        InventoryWebhookLog::query()
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->each(fn ($log) => self::dispatchLog($log, $setting));
    }

    private static function dispatchLog(InventoryWebhookLog $log, InventoryWebhookSetting $setting): void
    {
        $body = [
            'event' => $log->event,
            'sent_at' => now()->toIso8601String(),
            'payload' => $log->payload,
        ];

        try {
            $response = Http::timeout(max(1, (int) $setting->timeout_seconds))
                ->acceptJson()
                ->withHeaders([
                    'X-Inventory-Event' => $log->event,
                    'X-Inventory-Signature' => $setting->secret ? hash_hmac('sha256', json_encode($body, JSON_UNESCAPED_UNICODE), (string) $setting->secret) : '',
                ])
                ->post($setting->endpoint_url, $body);

            if ($response->successful()) {
                $log->update([
                    'status' => 'success',
                    'attempts' => (int) $log->attempts + 1,
                    'response_code' => $response->status(),
                    'error_message' => null,
                    'sent_at' => now(),
                    'next_retry_at' => null,
                ]);
                return;
            }

            $log->update([
                'status' => 'pending',
                'attempts' => (int) $log->attempts + 1,
                'response_code' => $response->status(),
                'error_message' => mb_substr((string) $response->body(), 0, 2000),
                'sent_at' => now(),
                'next_retry_at' => now()->addMinute(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'pending',
                'attempts' => (int) $log->attempts + 1,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'sent_at' => now(),
                'next_retry_at' => now()->addMinute(),
            ]);
        }
    }
}
