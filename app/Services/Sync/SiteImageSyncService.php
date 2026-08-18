<?php

namespace App\Services\Sync;

use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SiteImageSyncService {
    public function __construct() {
        $baseUrl = rtrim((string) Config::get('services.site.base_url'), '/');

        if ( $baseUrl === '' ) {
            throw new RuntimeException('آدرس services.site.base_url تنظیم نشده است.');
        }

        $this->apiUrl = $baseUrl . $this->url;
    }

    protected string  $url               = '/api/v2/sync/images';
    protected ?string $apiUrl;
    protected int     $batchSize         = 100;
    protected int     $maxRetries        = 2;
    protected int     $initialRetryDelay = 250;
    protected int     $timeout           = 30;

    /**
     * دریافت تصاویر سرور دوم و ذخیره در محصولات سرور اول.
     *
     * @return array<string, mixed>
     */
    public function syncAll(): array {
        $result = [
            'received'          => 0,
            'updated'           => 0,
            'verified'          => 0,
            'product_not_found' => 0,
            'invalid_items'     => 0,
            'errors'            => [],
        ];

        $afterId = 0;

        try {
            do {
                $responseData = $this->fetchPendingImages($afterId);
                $items        = $responseData['data'] ?? [];

                if ( !is_array($items) || $items === [] ) {
                    break;
                }

                $verifiedSourceIds = [];

                foreach ( $items as $item ) {
                    $result['received'] ++;
                    $image = $item['image'] ?? null;
                    $code  = $item['code'] ?? null;

                    $localProduct = Product::where('code', $code)
                        ->first();

                    if ( $localProduct === null ) {
                        continue;
                    }

                    $localProduct->update([
                        'image_path'                 => $image,
                        'site_to_inventory_verified' => true,
                    ]);

                    $verifiedSourceIds[] = $code;
                    $result['updated'] ++;
                }

                if ( $verifiedSourceIds !== [] ) {
                    $verifiedCount = $this->verifySourceProducts($verifiedSourceIds);

                    $result['verified'] += $verifiedCount;
                }
                /*
                 * اگر تعداد کمتر از Batch بود یعنی صفحه بعدی وجود ندارد.
                 */
            }
            while ( count($items) === $this->batchSize );
        } catch ( Throwable $exception ) {
            report($exception);

            $result['errors'][] = [
                'message' => $exception->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * دریافت محصولات تأییدنشده از سرور دوم.
     *
     * @return array<string, mixed>
     */
    private function fetchPendingImages( int $afterId ): array {
        try {
            $response = $this->httpClient()
                ->post($this->apiUrl, [
                    'limit'    => $this->batchSize,
                    'after_id' => $afterId,
                ]);
        } catch ( ConnectionException $exception ) {
            throw new RuntimeException('ارتباط با API دریافت تصاویر برقرار نشد: ' . $exception->getMessage(), previous : $exception);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException(sprintf('API دریافت تصاویر پاسخ نامعتبر داد. HTTP %d - %s', $response->status(), mb_substr($response->body(), 0, 500)));
        }

        $json = $response->json();

        if ( !is_array($json)
             || ( $json['success'] ?? false ) !== true
             || !isset($json['data'])
             || !is_array($json['data']) ) {
            throw new RuntimeException('ساختار پاسخ API دریافت تصاویر معتبر نیست.');
        }

        return $json;
    }

    /**
     * اعلام ذخیره موفق تصاویر به سرور دوم.
     *
     * @param array<int, int> $sourceProductIds
     */
    private function verifySourceProducts( array $sourceProductIds ): int {
        try {
            $response = $this->httpClient()
                ->post($this->apiUrl . '/verify', [
                    'product_ids' => array_values(array_unique($sourceProductIds)),
                ]);
        } catch ( ConnectionException $exception ) {
            throw new RuntimeException('ارتباط با API تأیید تصاویر برقرار نشد: ' . $exception->getMessage(), previous : $exception);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException(sprintf('API تأیید تصاویر پاسخ نامعتبر داد. HTTP %d - %s', $response->status(), mb_substr($response->body(), 0, 500)));
        }

        return (int) $response->json('data.updated_count', 0);
    }

    private function httpClient(): PendingRequest {
        return Http::withoutVerifying()
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($this->timeout)
            ->retry($this->maxRetries + 1, $this->initialRetryDelay, throw : false);
    }
}