<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InventorySyncService {
    public function __construct() {
        $this->apiUrl = Config::get('services.sales_server.api_url');
        $this->apiToken = Config::get('services.sales_server.api_token');
        $this->syncEnabled = (bool) Config::get('services.sales_server.sync_enabled', false);
    }

    protected ?string $apiUrl;
    protected ?string $apiToken;
    protected bool $syncEnabled;
    protected int    $batchSize          = 25;
    protected int    $maxRetries         = 2;
    protected int    $initialRetryDelay  = 1;

    protected int    $delayBetweenChunks = 1;

    protected string $timeout            = '7';

    public function syncAll(): array {
        if (! $this->isConfiguredForSync()) {
            return [
                'skipped' => true,
                'reason' => 'inventory_sync_disabled_or_not_configured',
            ];
        }

        $totalChunks  = 0;
        $successCount = 0;
        $failedChunks = [];

        Product::with([
            'variants' => function ( $query ) {
                $query->where('is_active', true);
            },
        ])
            ->chunk($this->batchSize, function ( Collection $products ) use ( &$totalChunks, &$successCount, &$failedChunks ) {
                $totalChunks ++;

                $payload = $this->buildPayload($products);
                $success = $this->sendWithRetry($payload, $totalChunks);

                if ( $success ) {
                    $successCount ++;
                    Log::info("Chunk {$totalChunks} synced successfully.");
                    usleep($this->delayBetweenChunks * 1000);
                }
                else {
                    $failedChunks[] = [
                        'chunk' => $totalChunks,
                        'error' => 'Failed after ' . $this->maxRetries . ' retries',
                    ];
                    Log::error("Chunk {$totalChunks} failed after retries.");
                }
            });

        Log::info("Sync completed. Total chunks: {$totalChunks}, successful: {$successCount}, failed: " . count($failedChunks));

        return [
            'total_chunks'  => $totalChunks,
            'success_count' => $successCount,
            'failed_chunks' => $failedChunks,
        ];
    }

    protected function isConfiguredForSync(): bool {
        return $this->syncEnabled
            && filled($this->apiUrl)
            && filled($this->apiToken);
    }

    protected function buildPayload( Collection $products ): array {
        $variantIds = $products->flatMap(function ( $product ) {
            return $product->variants->pluck('id');
        })
            ->unique()
            ->values()
            ->toArray();

        $stockMap = WarehouseStock::whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->select('product_variant_id', DB::raw('SUM(quantity) as total_stock'))
            ->pluck('total_stock', 'product_variant_id')
            ->toArray();

        return [
            'products' => $products->map(function ( Product $product ) use ( $stockMap ) {
                $productData = [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'code'          => $product->code,
                    'sku'           => $product->sku,
                    'short_barcode' => $product->short_barcode,
                    'is_sellable'   => (bool) $product->is_sellable,
                    'price'         => (int) ( $product->price ?? 0 ),
                    'variants'      => [],
                ];

                foreach ( $product->variants as $variant ) {
                    $productData['variants'][] = [
                        'id'                 => $variant->id,
                        'variant_name'       => $variant->variant_name,
                        'variant_code'       => $variant->variant_code,
                        'variety_name'       => $variant->variety_name,
                        'variety_code'       => $variant->variety_code,
                        'sell_price'         => (int) ( $variant->sell_price ?? 0 ),
                        'buy_price'          => (int) ( $variant->buy_price ?? 0 ),
                        'regular_price'      => (int) ( $variant->regular_price ?? $variant->sell_price ?? 0 ),
                        'discount_price'     => (int) ( $variant->discount_price ?? 0 ),
                        'discount_expire_at' => $variant->discount_expire_at?->toIso8601String(),
                        'stock'              => (int) ( $stockMap[$variant->id] ?? 0 ),
                        'reserved'           => (int) $variant->reserved,
                        'is_active'          => (bool) $variant->is_active,
                        'sales_enabled'      => (bool) ( $variant->sales_enabled ?? true ),
                    ];
                }

                return $productData;
            })
                ->values()
                ->toArray(),
        ];
    }

    protected function sendWithRetry( array $payload, int $chunkNumber ): bool {
        $attempt = 0;
        $delay   = $this->initialRetryDelay;

        while ( $attempt < $this->maxRetries ) {
            $attempt ++;

            try {
                $response = Http::withoutVerifying()
                    ->withToken($this->apiToken)
                    ->timeout((int) $this->timeout) // استفاده از پراپرتی timeout
                    ->post($this->apiUrl, $payload);

                if ( $response->successful() ) {
                    return true;
                }

                $status = $response->status();

                if ( $status === 503 ) {
                    Log::warning("Chunk {$chunkNumber} received 503, will retry with longer delay.");
                    $backoff = min(5 * pow(2, $attempt - 1), 60);
                    $this->sleepSeconds($backoff);
                    continue;
                }

                if ( $status >= 500 ) {
                    Log::warning("Chunk {$chunkNumber} attempt {$attempt} failed with status {$status}.", [
                        'body' => $response->body(),
                    ]);
                }
                elseif ( $status >= 400 && $status < 500 && $status !== 422 ) {
                    Log::warning("Chunk {$chunkNumber} client error {$status}, not retrying.", [
                        'body' => $response->body(),
                    ]);
                    return false;
                }
                else {
                    Log::warning("Chunk {$chunkNumber} attempt {$attempt} failed with status {$status}.", [
                        'body' => $response->body(),
                    ]);
                }
            } catch ( \Exception $e ) {
                Log::warning("Chunk {$chunkNumber} attempt {$attempt} threw exception: " . $e->getMessage());
                if ( str_contains($e->getMessage(), 'timed out') ) {
                    $this->sleepSeconds(10);
                }
            }

            if ( $attempt < $this->maxRetries ) {
                $this->sleepMilliseconds($delay);
                $delay *= 2;
            }
        }

        return false;
    }

    protected function sleepMilliseconds( int $milliseconds ): void {
        if ( $milliseconds > 0 ) {
            usleep($milliseconds * 1000);
        }
    }

    protected function sleepSeconds( int $seconds ): void {
        if ( $seconds > 0 ) {
            sleep($seconds);
        }
    }

    // Setters
    public function setBatchSize( int $size ): self {
        $this->batchSize = $size;
        return $this;
    }

    public function setMaxRetries( int $retries ): self {
        $this->maxRetries = $retries;
        return $this;
    }

    public function setDelayBetweenChunks( int $milliseconds ): self {
        $this->delayBetweenChunks = $milliseconds;
        return $this;
    }

    public function setTimeout( string $timeout ): self {
        $this->timeout = $timeout;
        return $this;
    }
}