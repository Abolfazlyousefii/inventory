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

        if (
            !Schema::hasTable('inventory_webhook_settings') ||
            !Schema::hasTable('inventory_webhook_logs')
        ) {
            return;
        }

        $setting = InventoryWebhookSetting::query()->latest('id')->first();

        if (
            !$setting ||
            !$setting->is_enabled ||
            empty($setting->endpoint_url) ||
            empty($setting->secret)
        ) {
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
        if (
            !Schema::hasTable('inventory_webhook_settings') ||
            !Schema::hasTable('inventory_webhook_logs')
        ) {
            return;
        }

        $setting = InventoryWebhookSetting::query()->latest('id')->first();

        if (
            !$setting ||
            !$setting->is_enabled ||
            empty($setting->endpoint_url) ||
            empty($setting->secret)
        ) {
            return;
        }

        InventoryWebhookLog::query()
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->each(
                fn (InventoryWebhookLog $log) =>
                self::dispatchLog($log, $setting)
            );
    }

    private static function dispatchLog(
        InventoryWebhookLog $log,
        InventoryWebhookSetting $setting
    ): void {
        try {
            /*
             * event id must remain stable between retries.
             */
            $eventId = 'inventory-webhook-' . $log->id;

            $timestamp = now()->toIso8601String();

            /*
             * Exact contract expected by Site's InventoryEventController.
             */
            $body = [
                'event_type' => $log->event,
                'payload' => $log->payload,
            ];

            $rawBody = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

            /*
             * Must match Site:
             * HMAC_SHA256(timestamp + "." + rawBody, sharedSecret)
             */
            $signature = hash_hmac(
                'sha256',
                $timestamp . '.' . $rawBody,
                (string) $setting->secret
            );

            $http = Http::timeout(
                max(1, (int) $setting->timeout_seconds)
            )->acceptJson();

            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $response = $http
                ->withHeaders([
                    'X-Ariya-Event-Id' => $eventId,
                    'X-Ariya-Timestamp' => $timestamp,
                    'X-Ariya-Signature' => $signature,
                    'X-Ariya-Source' => 'inventory',
                ])
                ->withBody($rawBody, 'application/json')
                ->post($setting->endpoint_url);


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
                'error_message' => mb_substr(
                    (string) $response->body(),
                    0,
                    2000
                ),
                'sent_at' => now(),
                'next_retry_at' => now()->addMinute(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'pending',
                'attempts' => (int) $log->attempts + 1,
                'error_message' => mb_substr(
                    $e->getMessage(),
                    0,
                    2000
                ),
                'sent_at' => now(),
                'next_retry_at' => now()->addMinute(),
            ]);
        }
    }
}