<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryProductsSyncService {
    protected ?string $apiUrl = null;

    protected ?string $apiToken = null;

    protected int $batchSize = 1;

    protected int $maxRetries = 2;

    /**
     * زمان انتظار اولیه Retry بر حسب میلی‌ثانیه.
     */
    protected int $initialRetryDelay = 1;

    /**
     * فاصله بین Batchها بر حسب میلی‌ثانیه.
     */
    protected int $delayBetweenChunks = 1;

    protected int $timeout = 30;

    /**
     * وضعیت دو مرحله مستقل است:
     *
     * inventory_to_site_synced:
     * داده Inventory در Site دریافت و ذخیره شده است.
     *
     * site_to_inventory_verified:
     * Site اطلاعات ذخیره‌شده را بررسی کرده و نتیجه تأیید را
     * به Inventory برگردانده است.
     */
    public function syncAll(): array {
        $this->apiUrl = trim((string) Config::get('services.sales_server.api_url'));
        $this->apiToken = trim((string) Config::get('services.sales_server.api_token'));

        if (! Config::get('services.sales_server.sync_enabled') || $this->apiUrl === '' || $this->apiToken === '') {
            return [
                'skipped' => true,
                'reason' => 'inventory_sync_disabled_or_not_configured',
            ];
        }

        $totalChunks          = 0;
        $successCount         = 0;
        $failedChunks         = [];

        Product::where(function ( $query ): void {
            $query->where('inventory_to_site_synced', false);
        })
            ->with([
                'variants' => function ( $query ): void {
                    $query->where('is_active', true);
                },
            ])
            ->orderBy('id')
            ->chunkById($this->batchSize, function ( Collection $products ) use (
                &$totalChunks, &$successCount, &$failedChunks
            ): void {
                $totalChunks ++;

                $expectedProductIds = $products->pluck('id')
                    ->map(static fn( $id ): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $payload = $this->buildPayload($products);

                $result = $this->sendWithRetry($payload, $totalChunks);

                if ( $result === null ) {
                    $failedChunks[] = [
                        'chunk'       => $totalChunks,
                        'product_ids' => $expectedProductIds,
                        'error'       => 'No valid response was received from Site.',
                    ];

                    Log::error("Inventory-to-Site chunk {$totalChunks} failed.", [ 'product_ids' => $expectedProductIds ]);

                    return;
                }

                $syncedProductIds = $this->onlyExpectedIds($result['synced_product_ids'], $expectedProductIds);
                $verifiedProductIds = $this->onlyExpectedIds($result['verified_product_ids'], $expectedProductIds);

                $syncComplete   = $syncedProductIds === $expectedProductIds;
                $verifyComplete = $verifiedProductIds === $expectedProductIds;

                if ( $syncComplete && $verifyComplete ) {
                    $successCount ++;

                    Log::info("Inventory-to-Site chunk {$totalChunks} synced and verified.", [
                        'synced_product_ids'   => $syncedProductIds,
                        'verified_product_ids' => $verifiedProductIds,
                    ]);
                }
                else {
                    $failedChunks[] = [
                        'chunk'                => $totalChunks,
                        'product_ids'          => $expectedProductIds,
                        'synced_product_ids'   => $syncedProductIds,
                        'verified_product_ids' => $verifiedProductIds,
                        'missing_sync_ids'     => array_values(array_diff($expectedProductIds, $syncedProductIds)),
                        'missing_verify_ids'   => array_values(array_diff($expectedProductIds, $verifiedProductIds)),
                        'error'                => 'Site returned a partial synchronization or verification response.',
                    ];

                    Log::warning("Inventory-to-Site chunk {$totalChunks} was only partially confirmed.", [
                        'expected_product_ids' => $expectedProductIds,
                        'synced_product_ids'   => $syncedProductIds,
                        'verified_product_ids' => $verifiedProductIds,
                    ]);
                }

                $this->sleepMilliseconds($this->delayBetweenChunks);
            }, 'id');

        $summary = [
            'total_chunks'  => $totalChunks,
            'success_count' => $successCount,
            'failed_chunks' => $failedChunks,
        ];

        Log::info('Inventory-to-Site synchronization completed.', $summary);

        return $summary;
    }

    protected function buildPayload( Collection $products ): array {
        $variantIds = $products->flatMap(static fn( Product $product ) => $product->variants->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $stockMap = $variantIds === []
            ? []
            : WarehouseStock::query()
                ->whereIn('product_variant_id', $variantIds)
                ->groupBy('product_variant_id')
                ->select('product_variant_id', DB::raw('SUM(quantity) as total_stock'))
                ->pluck('total_stock', 'product_variant_id')
                ->all();

        return [
            'products' => $products->map(function ( Product $product ) use ( $stockMap ): array {
                $productData = [
                    'id'            => (int) $product->id,
                    'name'          => (string) $product->name,
                    'code'          => $product->code,
                    'sku'           => $product->sku,
                    'short_barcode' => $product->short_barcode,
                    'is_sellable'   => (bool) $product->is_sellable,
                    'price'         => (int) ( $product->price ?? 0 ),
                    'variants'      => [],
                ];

                foreach ( $product->variants as $variant ) {
                    $productData['variants'][] = [
                        'id'                 => (int) $variant->id,
                        'variant_name'       => $variant->variant_name,
                        'variant_code'       => $variant->variant_code,
                        'sell_price'         => (int) ( $variant->sell_price ?? 0 ),
                        'buy_price'          => (int) ( $variant->buy_price ?? 0 ),
                        'regular_price'      => (int) ( $variant->regular_price ?? $variant->sell_price ?? 0 ),
                        'discount_price'     => (int) ( $variant->discount_price ?? 0 ),
                        'discount_expire_at' => $variant->discount_expire_at?->toIso8601String(),
                        'stock'              => (int) ( $stockMap[$variant->id] ?? 0 ),
                        'reserved'           => (int) ( $variant->reserved ?? 0 ),
                        'is_active'          => (bool) $variant->is_active,
                        'sales_enabled'      => (bool) ( $variant->sales_enabled ?? true ),
                    ];
                }

                return $productData;
            })
                ->values()
                ->all(),
        ];
    }

    /**
     * پاسخ معتبر Site شامل دو لیست مستقل است:
     *
     * synced_product_ids:
     * محصولاتی که در Site ذخیره شده‌اند.
     *
     * verified_product_ids:
     * محصولاتی که Site بعد از خواندن مجدد دیتابیس تأیید کرده است.
     */
    protected function sendWithRetry( array $payload, int $chunkNumber ): ?array {
        $attempt = 0;
        $delay   = $this->initialRetryDelay;

        $expectedProductIds = collect($payload['products'] ?? [])
            ->pluck('id')
            ->map(static fn( $id ): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ( $expectedProductIds === [] ) {
            return [
                'synced_product_ids'   => [],
                'verified_product_ids' => [],
            ];
        }

        while ( $attempt < $this->maxRetries ) {
            $attempt ++;

            try {
                $response = Http::withoutVerifying()
                    ->withToken($this->apiToken)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
                    ->post($this->apiUrl, $payload);

                if ( $response->successful() ) {
                    if ($response->json('ok') === true) {
                        return [
                            'synced_product_ids' => $expectedProductIds,
                            'verified_product_ids' => $expectedProductIds,
                        ];
                    }

                    $status = (string) $response->json('status', '');

                    $syncedProductIds = $this->normalizeIds($response->json('data.synced_product_ids', []));

                    $verifiedProductIds = $this->normalizeIds($response->json('data.verified_product_ids', []));

                    $unexpectedIds = array_values(array_diff(array_unique(array_merge($syncedProductIds, $verifiedProductIds)), $expectedProductIds));

                    if ( in_array($status, [ 'success', 'partial_success' ], true)
                         && $unexpectedIds === [] ) {
                        return [
                            'synced_product_ids'   => $syncedProductIds,
                            'verified_product_ids' => $verifiedProductIds,
                        ];
                    }

                    Log::warning("Chunk {$chunkNumber} returned an invalid application response.", [
                        'attempt'        => $attempt,
                        'status'         => $status,
                        'unexpected_ids' => $unexpectedIds,
                        'response'       => $response->json(),
                    ]);
                }
                else {
                    $statusCode = $response->status();

                    Log::warning("Chunk {$chunkNumber} HTTP request failed.", [
                        'attempt'     => $attempt,
                        'status_code' => $statusCode,
                        'body'        => $response->body(),
                    ]);

                    if ( $statusCode >= 400
                         && $statusCode < 500 ) {
                        return null;
                    }
                }
            } catch ( Throwable $exception ) {
                Log::warning("Chunk {$chunkNumber} request threw an exception.", [
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);
            }

            if ( $attempt < $this->maxRetries ) {
                $this->sleepMilliseconds($delay);
                $delay *= 2;
            }
        }

        return null;
    }

    protected function normalizeIds( mixed $ids ): array {
        if ( !is_array($ids) ) {
            return [];
        }

        return collect($ids)
            ->filter(static fn( $id ): bool => is_int($id)
                                               || ( is_string($id)
                                                    && ctype_digit($id) ))
            ->map(static fn( $id ): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function onlyExpectedIds( array $ids, array $expectedIds ): array {
        $expectedMap = array_fill_keys($expectedIds, true);

        return collect($ids)
            ->filter(static fn( int $id ): bool => isset($expectedMap[$id]))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function sleepMilliseconds( int $milliseconds ): void {
        if ( $milliseconds > 0 ) {
            usleep($milliseconds * 1000);
        }
    }

    public function setBatchSize( int $size ): self {
        $this->batchSize = max(1, $size);

        return $this;
    }

    public function setMaxRetries( int $retries ): self {
        $this->maxRetries = max(1, $retries);

        return $this;
    }

    public function setDelayBetweenChunks( int $milliseconds ): self {
        $this->delayBetweenChunks = max(0, $milliseconds);

        return $this;
    }

    public function setTimeout( int $timeout ): self {
        $this->timeout = max(1, $timeout);

        return $this;
    }
}
