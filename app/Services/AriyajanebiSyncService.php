<?php

namespace App\Services;

use App\Models\InventoryWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AriyajanebiSyncService
{
    private const LOGIN_URL = 'https://api.ariyajanebi.ir/v1/admin/login';
    private const UPDATE_URL = 'https://api.ariyajanebi.ir/v1/admin/multi_varieties_update_lite';
    private const EVENT = 'ariya.multi_varieties_update_lite';

    public static function syncProduct(Product $product): void
    {
        try {
            self::processPending();
            $variants = $product->variants()->whereNotNull('variety_id')->get(['id', 'variety_id']);
            self::syncVariants($variants);
        } catch (\Throwable $exception) {
            // A delivery failure after commit must never make the caller repeat
            // the original purchase, sale, or stock operation.
            Log::warning('Ariyajanebi product sync could not be queued.', [
                'product_id' => $product->id,
                'exception' => $exception::class,
            ]);
        }
    }

    public static function syncVariant(ProductVariant $variant): void
    {
        if ($variant->variety_id === null) {
            return;
        }
        try {
            self::processPending();
            self::syncVariants(collect([$variant]));
        } catch (\Throwable $exception) {
            Log::warning('Ariyajanebi variant sync could not be queued.', [
                'variant_id' => $variant->id,
                'exception' => $exception::class,
            ]);
        }
    }

    public static function processPending(): void
    {
        if (! Schema::hasTable('inventory_webhook_logs')) {
            return;
        }

        // A processing lease also makes interrupted HTTP sends retryable. Claiming
        // a log prevents two workers from replaying the same log simultaneously.
        $logs = InventoryWebhookLog::query()
            ->where('event', self::EVENT)
            ->whereIn('status', ['pending', 'processing'])
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')
            ->limit(20)
            ->get();


        foreach ($logs as $log) {
            $claimed = InventoryWebhookLog::query()
                ->whereKey($log->id)
                ->whereIn('status', ['pending', 'processing'])
                ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
                ->update(['status' => 'processing', 'next_retry_at' => now()->addMinutes(2)]);

            if ($claimed !== 1) {
                continue;
            }

            // The claim updated the database without updating this loaded model.
            // Refresh its original status to 'processing', so a failed HTTP send
            // can persist the transition back to 'pending' via Eloquent dirty tracking.
            $log->refresh();

            // Never send payload['payload'] from a historical log. Use it only
            // to identify the variants that need a new canonical snapshot.
            $references = self::referencesFromLog($log);
            self::sendCurrentVariants($references, $log);
        }
    }

    private static function syncVariants($variants): void
    {
        $references = [];
        foreach ($variants as $variant) {
            if ($variant->variety_id !== null) {
                $references[] = [
                    'variant_id' => (int) $variant->id,
                    'variety_id' => (string) $variant->variety_id,
                ];
            }
        }
        if ($references === []) {
            return;
        }

        // The initial log is an intent for named variants, not a durable price
        // or stock snapshot. Retries reconstruct both values after login.
        $log = self::createApiLog($references);
        self::sendCurrentVariants($references, $log);
    }

    /** @return array<int, array{variant_id: int|null, variety_id: string}> */
    private static function referencesFromLog(InventoryWebhookLog $log): array
    {
        $stored = (array) ($log->payload ?? []);
        $references = [];

        foreach ((array) ($stored['variants'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $remoteId = trim((string) $row['id']);
            if ($remoteId !== '') {
                $references[] = [
                    'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
                    'variety_id' => $remoteId,
                ];
            }
        }

        if ($references !== []) {
            return $references;
        }

        // Backward compatibility for records saved before the variants preview
        // existed. Only remote IDs may be reused; their saved values never are.
        foreach ((array) ($stored['payload'] ?? []) as $key => $value) {
            if (preg_match('/^varieties\[\d+\]\[id\]$/', (string) $key) !== 1) {
                continue;
            }
            $remoteId = trim((string) $value);
            if ($remoteId !== '') {
                $references[] = ['variant_id' => null, 'variety_id' => $remoteId];
            }
        }

        return $references;
    }

    /**
     * Re-read sell_price and sellable stock after authentication, immediately
     * before the outgoing update request. Automatic bundle quantities come
     * from protected component availability, not a warehouse stock row.
     *
     * @param array<int, array{variant_id: int|null, variety_id: string}> $references
     */
    private static function currentSnapshot(array $references): array
    {
        $variants = collect();
        foreach ($references as $reference) {
            $remoteId = $reference['variety_id'];
            if ($reference['variant_id'] !== null && $reference['variant_id'] > 0) {
                $variant = ProductVariant::query()
                    ->whereKey($reference['variant_id'])
                    ->where('variety_id', $remoteId)
                    ->first();
            } else {
                // An ambiguous legacy remote ID is unsafe to replay.
                $matches = ProductVariant::query()->where('variety_id', $remoteId)->limit(2)->get();
                $variant = $matches->count() === 1 ? $matches->first() : null;
            }

            if ($variant !== null) {
                $variants->put((int) $variant->id, $variant);
            }
        }

        if ($variants->isEmpty()) {
            return [[], []];
        }

        $variants = new \Illuminate\Database\Eloquent\Collection($variants->values()->all());
        $variants->load('product:id,name,code,sku,barcode,is_bundle,bundle_stock_mode');
        $quantities = WarehouseStock::query()
            ->whereIn('warehouse_id', WarehouseStockService::siteSellableWarehouseIds())
            ->whereIn('product_variant_id', $variants->pluck('id')->all())
            ->selectRaw('product_variant_id, SUM(quantity) AS total_quantity')
            ->groupBy('product_variant_id')
            ->pluck('total_quantity', 'product_variant_id');

        $payload = ['_method' => 'PUT'];
        foreach ($variants as $i => $variant) {
            $payload["varieties[{$i}][id]"] = (string) $variant->variety_id;
            // Database prices and external API prices are both in Rial.
            $payload["varieties[{$i}][price]"] = (string) max(0, (int) $variant->sell_price);
            // The automatic kit is backed by protected component allocations,
            // not by a sellable stock row for the bundle variant itself. Match
            // the scheduled Site sync's availability rule on this API path.
            $balance = $variant->product?->is_bundle
                && $variant->product?->bundle_stock_mode === 'automatic'
                ? app(AutomaticBundleAvailabilityService::class)->available((int) $variant->id)
                : (int) ($quantities[$variant->id] ?? 0);
            $payload["varieties[{$i}][balance]"] = (string) max(0, $balance);
        }

        return [$payload, self::variantPayloadPreview($payload, $variants)];
    }

    /** @param array<int, array{variant_id: int|null, variety_id: string}> $references */
    private static function sendCurrentVariants(array $references, ?InventoryWebhookLog $log): void
    {
        if ($references === []) {
            self::markApiLog($log, 'skipped', null, 'retry has no variant identifiers');
            return;
        }

        $username = (string) config('services.ariyajanebi_sync.username', '');
        $password = (string) config('services.ariyajanebi_sync.password', '');
        if ($username === '' || $password === '') {
            self::markApiLog($log, 'pending', null, 'integration credentials are not configured');
            return;
        }

        try {
            $client = Http::asForm()->timeout(15)->withOptions(['allow_redirects' => false]);
            $login = $client->post(self::LOGIN_URL, [
                'username' => $username,
                'password' => $password,
            ]);

            if (! $login->successful()) {
                self::markApiLog($log, 'pending', $login->status(), 'login HTTP failure');
                return;
            }

            // Login may take time; don't use a price/stock snapshot captured
            // before authentication or reuse the saved retry payload.
            [$payload, $preview] = self::currentSnapshot($references);
            if ($preview === []) {
                self::markApiLog($log, 'skipped', null, 'source variant missing or remote identifier changed');
                Log::notice('Ariyajanebi sync retry safely skipped an obsolete variant reference.', [
                    'webhook_log_id' => $log?->id,
                ]);
                return;
            }

            $dropped = count($references) - count($preview);
            if ($dropped > 0) {
                Log::notice('Ariyajanebi sync ignored obsolete variant references.', [
                    'webhook_log_id' => $log?->id,
                    'count' => $dropped,
                ]);
            }
            if ($log !== null) {
                $log->update(['payload' => [
                    'payload' => $payload,
                    'variants' => $preview,
                    'dropped_references' => max(0, $dropped),
                ]]);
            }

            $updateClient = $client->withCookies($login->cookies()->toArray(), 'api.ariyajanebi.ir');
            $token = self::extractToken($login->json());
            if ($token !== null) {
                $updateClient = $updateClient->withToken($token)->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'X-API-TOKEN' => $token,
                ]);
            }

            $response = $updateClient->post(self::UPDATE_URL, $payload);
            if ($response->successful()) {
                self::markApiLog($log, 'success', $response->status(), null);
            } else {
                // Never write response bodies, bearer tokens, URLs with query
                // strings or credentials into the durable retry audit log.
                self::markApiLog($log, 'pending', $response->status(), 'update HTTP failure');
            }
        } catch (\Throwable $exception) {
            self::markApiLog($log, 'pending', null, 'integration transport failure');
            Log::warning('Ariyajanebi sync transport failed.', [
                'webhook_log_id' => $log?->id,
                'exception' => $exception::class,
            ]);
            // This integration is a side effect. Failure must not cause a caller
            // to retry a committed purchase, sale, or stock movement.
        }
    }

    /** @param array<int, array{variant_id: int|null, variety_id: string}> $references */
    private static function createApiLog(array $references): ?InventoryWebhookLog
    {
        if (! Schema::hasTable('inventory_webhook_logs')) {
            return null;
        }

        // Persist an intent, not old values that may later be sent by mistake.
        return InventoryWebhookLog::query()->create([
            'event' => self::EVENT,
            'target' => self::UPDATE_URL,
            'status' => 'processing',
            'attempts' => 0,
            'payload' => [
                'variants' => array_map(fn (array $reference) => [
                    'id' => $reference['variety_id'],
                    'variant_id' => $reference['variant_id'],
                ], $references),
            ],
            'sent_at' => null,
            'next_retry_at' => now()->addMinutes(2),
        ]);
    }

    private static function variantPayloadPreview(array $payload, $variants): array
    {
        $rows = [];
        foreach ($variants->values() as $i => $variant) {
            $product = $variant->product;
            $rows[] = [
                'id' => $payload["varieties[{$i}][id]"],
                'price' => $payload["varieties[{$i}][price]"],
                'balance' => $payload["varieties[{$i}][balance]"],
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
        if ($log === null) {
            return;
        }

        $log->update([
            'status' => $status,
            'attempts' => (int) $log->attempts + 1,
            'response_code' => $code,
            'error_message' => $error,
            'sent_at' => now(),
            'next_retry_at' => $status === 'pending' ? now()->addMinute() : null,
        ]);
    }

    private static function extractToken($json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        foreach ([
            $json['token'] ?? null,
            $json['access_token'] ?? null,
            $json['data']['token'] ?? null,
            $json['data']['access_token'] ?? null,
            $json['result']['token'] ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
