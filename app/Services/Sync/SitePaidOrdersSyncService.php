<?php

namespace App\Services\Sync;

use App\Services\ExternalPaidOrderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SitePaidOrdersSyncService {
    public function __construct( private readonly ExternalPaidOrderService $orderService ) {
        $baseUrl = rtrim((string) Config::get('services.site.base_url'), '/');

        if ( $baseUrl === '' ) {
            throw new RuntimeException('آدرس services.site.base_url تنظیم نشده است.');
        }

        $this->apiUrl = $baseUrl . $this->url;
    }

    protected string $url = '/api/v2/sync/orders';
    protected string $apiUrl;

    /** @return array{received: int, created: int, existing: int, skipped: int, invalid: int, errors: array<int, array<string, mixed>>} */
    public function syncAll(): array {
        $summary = [
            'received' => 0,
            'created'  => 0,
            'existing' => 0,
            'skipped'  => 0,
            'invalid'  => 0,
            'errors'   => [],
        ];

        try {
            foreach ( $this->fetchOrders() as $order ) {
                $summary['received'] ++;

                if ( !is_array($order) || ( $order['status'] ?? null ) !== 'paid' ) {
                    $summary['skipped'] ++;
                    continue;
                }

                try {
                    $result = $this->orderService->import($this->toImportPayload($order), false);

                    $summary[$result['created'] ? 'created' : 'existing'] ++;
                } catch ( Throwable $exception ) {
                    $summary['invalid'] ++;
                    $summary['errors'][] = [
                        'order_id' => $order['id'] ?? null,
                        'message'  => $exception->getMessage(),
                    ];
                }
            }
        } catch ( Throwable $exception ) {
            report($exception);
            $summary['errors'][] = [ 'message' => $exception->getMessage() ];
        }

        return $summary;
    }

    /** @return array<int, mixed> */
    private function fetchOrders(): array {
        try {
            $response = $this->httpClient()
                ->get($this->apiUrl);
        } catch ( ConnectionException $exception ) {
            throw new RuntimeException('ارتباط با API سفارش‌های سایت برقرار نشد: ' . $exception->getMessage(), previous : $exception);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException($response->body());
        }

        $json   = $response->json();
        $orders = data_get($json, 'result.data');

        if ( !is_array($json) || ( $json['success'] ?? false ) !== true || !is_array($orders) ) {
            throw new RuntimeException('ساختار پاسخ API سفارش‌های سایت معتبر نیست.');
        }

        return array_values($orders);
    }

    private function toImportPayload( array $order ): array {
        $items = $order['items'] ?? null;

        if ( (int) ( $order['id'] ?? 0 ) <= 0 || !is_array($items) || $items === [] ) {
            throw new RuntimeException('شناسه سفارش یا اقلام آن معتبر نیست.');
        }

        return [
            'crm_order_id'     => (int) $order['id'],
            'occurred_at'      => $order['created_at'] ?? null,
            'user'             => [
                'id'          => data_get($order, 'customer.id'),
                'crm_user_id' => data_get($order, 'customer.id'),
                'name'        => data_get($order, 'customer.name'),
                'mobile'      => data_get($order, 'customer.mobile'),
                'username'    => data_get($order, 'customer.username'),
            ],
            'shipping_address' => [
                'name'        => data_get($order, 'customer.name'),
                'mobile'      => data_get($order, 'customer.mobile'),
                'province'    => [ 'name' => data_get($order, 'delivery.province') ],
                'city'        => [ 'name' => data_get($order, 'delivery.city') ],
                'postal_code' => data_get($order, 'delivery.postal_code'),
                'address'     => data_get($order, 'delivery.address'),
            ],
            'order'            => [
                'id'              => (int) $order['id'],
                'shipping_price'  => max((int) ( $order['shipping_cost'] ?? 0 ), 0),
                'discount_amount' => max((int) ( $order['discount_amount'] ?? 0 ), 0),
                'total'           => max((int) ( $order['price'] ?? 0 ), 0),
            ],
            'items'            => array_map(fn( array $item ): array => [
                'product_id'      => $item['product_id'] ?? null,
                'price_id'        => $item['price_id'] ?? null,
                'title'           => $item['title'] ?? null,
                'variant_code'    => $item['variant_code'] ?? null,
                'stock_code'      => $item['stock_code'] ?? null,
                'quantity'        => max((int) ( $item['quantity'] ?? 0 ), 0),
                'price'           => max((int) ( $item['real_unit_price'] ?? $item['unit_price'] ?? 0 ), 0),
                'discount_amount' => max((int) ( $item['discount'] ?? 0 ), 0),
            ], array_values($items)),
        ];
    }

    private function httpClient(): PendingRequest {
        $request = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(3, 250, throw : false);
        $token   = trim((string) Config::get('services.site.token', ''));

        return $token !== '' ? $request->withToken($token) : $request;
    }
}
