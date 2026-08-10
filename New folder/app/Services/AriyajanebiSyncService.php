<?php

namespace App\Services;

use App\Models\InventoryWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AriyajanebiSyncService
{
    private const LOGIN_URL = 'https://api.ariyajanebi.ir/v1/admin/login';
    private const UPDATE_URL = 'https://api.ariyajanebi.ir/v1/admin/multi_varieties_update_lite';
    private const USERNAME = 'admin';
    private const PASSWORD = 'Z.adeli60';

    public static function syncProduct(Product $product): void
    {
        self::processPending();

        $variants = $product->variants()->whereNotNull('variety_id')->get([
            'id',
            'product_id',
            'variety_id',
            'sell_price',
            'stock',
            'variant_code',
            'variety_code',
            'variant_name',
            'variety_name',
        ]);
        if ($variants->isEmpty()) return;
        $variants->each->setRelation('product', $product);
        self::syncVariants($variants, false);
    }

    public static function syncVariant(ProductVariant $variant): void
    {
        self::processPending();

        if (empty($variant->variety_id)) return;
        if (!$variant->relationLoaded('product')) {
            $variant->loadMissing('product:id,name,code,sku,barcode');
        }
        self::syncVariants(collect([$variant]), false);
    }

    public static function processPending(): void
    {
        if (!Schema::hasTable('inventory_webhook_logs')) return;

        InventoryWebhookLog::query()
            ->where('event', 'ariya.multi_varieties_update_lite')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(function (InventoryWebhookLog $log): void {
                $payload = (array) data_get($log->payload, 'payload', []);
                if (empty($payload)) {
                    $log->update([
                        'attempts' => (int) $log->attempts + 1,
                        'error_message' => 'payload missing for retry',
                        'next_retry_at' => now()->addMinute(),
                        'sent_at' => now(),
                    ]);
                    return;
                }

                self::sendPayload($payload, false, $log);
            });
    }

    private static function syncVariants($variants, bool $withoutVerify): void
    {
        $payload = ['_method' => 'PUT'];
        foreach ($variants->values() as $i => $variant) {
            $payload["varieties[{$i}][id]"] = (string) $variant->variety_id;
            // Monetary values are stored and sent exactly as entered in Rial; do not apply unit conversion here.
            $payload["varieties[{$i}][price]"] = (string) max(0, (int) $variant->sell_price);
            $payload["varieties[{$i}][balance]"] = (string) self::centralWarehouseQuantityForVariant($variant);
        }

        $apiLog = self::createApiLog($payload, $variants);
        self::sendPayload($payload, $withoutVerify, $apiLog);
    }

    private static function sendPayload(array $payload, bool $withoutVerify, ?InventoryWebhookLog $apiLog): void
    {

        try {
            $client = Http::asForm()->timeout(15)->withOptions(['allow_redirects' => false]);
            if ($withoutVerify) $client = $client->withoutVerifying();

            $login = $client->post(self::LOGIN_URL, [
                'username' => self::USERNAME,
                'password' => self::PASSWORD,
            ]);

            if (!$login->successful()) {
                self::markApiLog($apiLog, 'pending', $login->status(), 'login failed');
                return;
            }

            $updateClient = $client->withCookies($login->cookies()->toArray(), 'api.ariyajanebi.ir');
            $token = self::extractToken($login->json());
            if ($token) {
                $updateClient = $updateClient->withToken($token)->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'X-API-TOKEN' => $token,
                ]);
            }

            $update = $updateClient->post(self::UPDATE_URL, $payload);

            if ($update->status() >= 300 && $update->status() < 400) {
                self::markApiLog($apiLog, 'pending', $update->status(), 'redirect to ' . ($update->header('Location') ?? 'unknown'));
                return;
            }

            if ($update->successful()) {
                self::markApiLog($apiLog, 'success', $update->status(), null);
            } else {
                self::markApiLog($apiLog, 'pending', $update->status(), mb_substr((string) $update->body(), 0, 500));
            }
        } catch (\Throwable $e) {
            if (!$withoutVerify && str_contains($e->getMessage(), 'cURL error 77')) {
                Log::warning('Ariyajanebi SSL cert issue detected, retrying without SSL verification.');
                self::sendPayload($payload, true, $apiLog);
                return;
            }

            self::markApiLog($apiLog, 'pending', null, mb_substr($e->getMessage(), 0, 500));
            Log::error('Ariyajanebi sync exception', ['message' => $e->getMessage(), 'without_verify' => $withoutVerify]);
        }
    }

    private static function createApiLog(array $payload, $variants): ?InventoryWebhookLog
    {
        if (!Schema::hasTable('inventory_webhook_logs')) return null;

        return InventoryWebhookLog::create([
            'event' => 'ariya.multi_varieties_update_lite',
            'target' => self::UPDATE_URL,
            'status' => 'pending',
            'attempts' => 1,
            'payload' => [
                'payload' => $payload,
                'variants' => self::variantPayloadPreview($payload, $variants),
            ],
            'sent_at' => now(),
            'next_retry_at' => now()->addMinute(),
        ]);
    }

    private static function variantPayloadPreview(array $payload, $variants): array
    {
        $rows = [];
        foreach ($variants->values() as $i => $variant) {
            $product = $variant->product;

            $rows[] = [
                'id' => $payload["varieties[{$i}][id]"] ?? null,
                'price' => $payload["varieties[{$i}][price]"] ?? null,
                'balance' => $payload["varieties[{$i}][balance]"] ?? null,
                'product_id' => $variant->product_id,
                'product_name' => $product?->name,
                'product_code' => $product?->code,
                'product_sku' => $product?->sku,
                'product_barcode' => $product?->barcode,
                'variant_id' => $variant->id,
                'variant_name' => $variant->variant_name,
                'variant_code' => $variant->variant_code,
                'variety_name' => $variant->variety_name,
                'variety_code' => $variant->variety_code,
                'variant_sku' => $variant->sku,
            ];
        }
        return $rows;
    }

    private static function markApiLog(?InventoryWebhookLog $log, string $status, ?int $code, ?string $error): void
    {
        if (!$log) return;

        $log->update([
            'status' => $status,
            'attempts' => (int) $log->attempts + 1,
            'response_code' => $code,
            'error_message' => $error,
            'sent_at' => now(),
            'next_retry_at' => $status === 'success' ? null : now()->addMinute(),
        ]);
    }

    private static function centralWarehouseQuantityForVariant($variant): int
    {
        // Reserved preinvoice items are moved out of central stock immediately,
        // so the variant stock field already represents saleable central stock.
        return max(0, (int) ($variant->stock ?? 0));
    }

    private static function extractToken($json): ?string
    {
        if (!is_array($json)) return null;

        $candidates = [
            $json['token'] ?? null,
            $json['access_token'] ?? null,
            $json['data']['token'] ?? null,
            $json['data']['access_token'] ?? null,
            $json['result']['token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') return $value;
        }

        return null;
    }
}
