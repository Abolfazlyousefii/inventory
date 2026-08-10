<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\ProductVariant;
use App\Support\DocumentCodeGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AriyajanebiOrderImportService
{
    private const LOGIN_URL = 'https://api.ariyajanebi.ir/v1/admin/login';
    private const ORDERS_URL = 'https://api.ariyajanebi.ir/v1/admin/orders';
    private const USERNAME = 'admin';
    private const PASSWORD = 'Z.adeli60';

    private ?string $lastError = null;
    private bool $sslVerifyDisabledForRuntime = false;
    private int $maxAttempts = 3;
    private const SSL_WARNING_CACHE_KEY = 'ariya_order_import_ssl77_warning_logged';

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function importPendingOrders(): int
    {
        return $this->importFromLastImportedOnFirstPage(2360);
    }

    public function importFromLastImportedOnFirstPage(int $minimumStartId = 2360): int
    {
        $this->lastError = null;

        $this->sslVerifyDisabledForRuntime = false;

        $client = $this->authenticatedClient();
        if (!$client) {
            if (!$this->lastError) {
                $this->lastError = 'اتصال/ورود به API آریاجنبی ناموفق بود.';
            }
            return 0;
        }

        $response = $this->sendWithRetry($client, self::ORDERS_URL . '?page=1');
        if (!$response) {
            $this->lastError = $this->lastError ?: 'عدم پاسخ API در دریافت لیست سفارش‌ها.';
            return 0;
        }
        if (!$response->successful()) {
            Log::warning('Ariya orders list failed', ['status' => $response->status()]);
            $this->lastError = 'دریافت لیست سفارشات از API ناموفق بود. HTTP ' . $response->status();
            return 0;
        }

        $orders = $this->extractOrdersCollection($response->json());

        $maxApiOrderId = (int) $orders->map(fn (array $row) => $this->extractOrderId($row))->max();
        if ($maxApiOrderId <= 0) {
            return 0;
        }

        $lastImportedId = (int) (Invoice::query()->max('external_order_id') ?? 0);
        $startId = max($minimumStartId, $lastImportedId + 1);
        if ($startId > $maxApiOrderId) {
            return 0;
        }

        $created = 0;
        for ($orderId = $startId; $orderId <= $maxApiOrderId; $orderId++) {
            if (PreinvoiceOrder::query()->where('external_order_id', $orderId)->exists()) {
                continue;
            }

            $detail = $this->sendWithRetry($client, self::ORDERS_URL . '/' . $orderId, 1);
            if (!$detail) {
                Log::warning('Ariya order detail unreachable', ['order_id' => $orderId]);
                continue;
            }
            if (!$detail->successful()) {
                if ($detail->status() !== 404) {
                    Log::warning('Ariya order detail failed', ['order_id' => $orderId, 'status' => $detail->status()]);
                }
                continue;
            }

            $detailRow = $this->extractOrderDetailRow($detail->json());
            if (!is_array($detailRow)) {
                Log::warning('Ariya order detail payload invalid', ['order_id' => $orderId]);
                continue;
            }

            if ($this->createPreinvoiceFromExternalOrder($detailRow)) {
                $created++;
            }
        }

        return $created;
    }

    public function latestOrderSnapshot(): ?array
    {
        $this->lastError = null;
        $this->sslVerifyDisabledForRuntime = false;

        $client = $this->authenticatedClient();
        if (!$client) {
            return null;
        }

        $response = $this->sendWithRetry($client, self::ORDERS_URL);
        if (!$response || !$response->successful()) {
            if ($response && !$response->successful()) {
                $this->lastError = 'دریافت لیست سفارشات از API ناموفق بود. HTTP ' . $response->status();
            }
            return null;
        }

        $orders = $this->extractOrdersCollection($response->json());

        $latest = $orders
            ->sortByDesc(fn (array $row) => $this->extractOrderId($row))
            ->first();

        if (!is_array($latest)) {
            return null;
        }

        $externalId = $this->extractOrderId($latest);

        return [
            'id' => $externalId,
            'created_at' => Arr::get($latest, 'created_at'),
            'status' => Arr::get($latest, 'status'),
            'total' => Arr::get($latest, 'total'),
            'raw_id' => Arr::get($latest, 'id', Arr::get($latest, 'order.id')),
            'already_imported' => $externalId > 0
                ? Invoice::query()->where('external_order_id', $externalId)->exists()
                : false,
        ];
    }

    public function firstOrderSnapshot(): ?array
    {
        $this->lastError = null;
        $this->sslVerifyDisabledForRuntime = false;

        $client = $this->authenticatedClient();
        if (!$client) {
            return null;
        }

        $response = $this->sendWithRetry($client, self::ORDERS_URL . '?per_page=1&page=1', 1);
        if (!$response || !$response->successful()) {
            if ($response && !$response->successful()) {
                $this->lastError = 'دریافت لیست سفارشات از API ناموفق بود. HTTP ' . $response->status();
            }
            return null;
        }

        $orders = $this->extractOrdersCollection($response->json());
        $first = $orders->first();
        if (!is_array($first)) {
            return null;
        }

        $externalId = $this->extractOrderId($first);

        return [
            'id' => $externalId,
            'created_at' => Arr::get($first, 'created_at'),
            'status' => Arr::get($first, 'status'),
            'total' => Arr::get($first, 'total'),
            'raw_id' => Arr::get($first, 'id', Arr::get($first, 'order.id')),
            'already_imported' => $externalId > 0
                ? Invoice::query()->where('external_order_id', $externalId)->exists()
                : false,
        ];
    }

    private function extractOrdersCollection(mixed $json): Collection
    {
        $data = Arr::get($json, 'data.orders.data');

        if (!is_array($data)) {
            $data = Arr::get($json, 'data.orders');
        }

        if (!is_array($data)) {
            $data = Arr::get($json, 'data', $json);
        }

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return collect(is_array($data) ? $data : [])->filter(fn ($row) => is_array($row));
    }

    private function extractOrderId(array $order): int
    {
        $candidates = [
            Arr::get($order, 'id'),
            Arr::get($order, 'order_id'),
            Arr::get($order, 'order.id'),
            Arr::get($order, 'orderId'),
            Arr::get($order, 'order.id.value'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    private function createPreinvoiceFromExternalOrder(array $order): bool
    {
        $externalOrderId = $this->extractOrderId($order);
        if ($externalOrderId <= 0) {
            Log::warning('External order id could not be extracted', ['order_payload_keys' => array_keys($order)]);
            return false;
        }

        return (bool) DB::transaction(function () use ($order, $externalOrderId) {
            if (PreinvoiceOrder::query()->where('external_order_id', $externalOrderId)->lockForUpdate()->exists()) {
                return false;
            }

            $items = $this->extractItems($order);
            if ($items->isEmpty()) {
                return false;
            }

            $resolvedItems = [];
            $subtotal = 0;

            foreach ($items as $item) {
                $siteCode = (int) Arr::get($item, 'variety_id', Arr::get($item, 'site_code', Arr::get($item, 'id')));
                $quantity = max(1, (int) Arr::get($item, 'quantity', 1));
                $price = max(0, (int) Arr::get($item, 'price', Arr::get($item, 'unit_price', 0)));

                $variant = ProductVariant::query()->where('variety_id', $siteCode)->lockForUpdate()->first();
                if (!$variant) {
                    Log::warning('Variant not found for site code', ['site_code' => $siteCode, 'order_id' => $externalOrderId]);
                    continue;
                }

                if ((int) $variant->stock < $quantity) {
                    Log::warning('Insufficient variant stock for import', ['variant_id' => $variant->id, 'order_id' => $externalOrderId]);
                    continue;
                }

                $lineTotal = $quantity * $price;
                $subtotal += $lineTotal;

                $resolvedItems[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'price' => $price,
                    'line_total' => $lineTotal,
                ];
            }

            if (empty($resolvedItems)) {
                return false;
            }

            $customerName = trim((string) (Arr::get($order, 'customer_name')
                ?? Arr::get($order, 'customer.full_name')
                ?? Arr::get($order, 'customer.name')
                ?? Arr::get($order, 'customer.mobile')
                ?? 'مشتری سایت'));

            if ($customerName === '') {
                $customerName = 'مشتری سایت';
            }

            $customerAddress = Arr::get($order, 'customer_address', Arr::get($order, 'customer.address'));
            if (!is_string($customerAddress) || trim($customerAddress) === '') {
                $customerAddress = (string) (Arr::get($order, 'address') ?: '—');
            }
            $customerAddress = $this->normalizeAddressText($customerAddress);

            $preinvoice = PreinvoiceOrder::query()->create([
                'uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class),
                'external_order_id' => $externalOrderId,
                'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                'customer_name' => $customerName,
                'customer_mobile' => Arr::get($order, 'customer_mobile', Arr::get($order, 'customer.mobile')),
                'customer_address' => $customerAddress,
                'shipping_price' => max(0, (int) Arr::get($order, 'shipping_price', Arr::get($order, 'shipping_amount', 0))),
                'shipping_id' => (int) Arr::get($order, 'shipping_id', 0),
                'discount_amount' => max(0, (int) Arr::get($order, 'discount_amount', 0)),
                'total_price' => max($subtotal + (int) Arr::get($order, 'shipping_price', Arr::get($order, 'shipping_amount', 0)) - (int) Arr::get($order, 'discount_amount', 0), 0),
                'province_id' => (int) Arr::get($order, 'province_id', 0),
                'city_id' => Arr::get($order, 'city_id') !== null ? (int) Arr::get($order, 'city_id') : null,
            ]);

            foreach ($resolvedItems as $row) {
                /** @var ProductVariant $variant */
                $variant = $row['variant'];

                PreinvoiceOrderItem::query()->create([
                    'preinvoice_order_id' => $preinvoice->id,
                    'product_id' => (int) $variant->product_id,
                    'variant_id' => (int) $variant->id,
                    'quantity' => (int) $row['quantity'],
                    'price' => (int) $row['price'],
                ]);
            }

            return true;
        });
    }

    private function extractItems(array $order): Collection
    {
        $candidates = [
            Arr::get($order, 'items', []),
            Arr::get($order, 'order_items', []),
            Arr::get($order, 'orderItems', []),
            Arr::get($order, 'data.items', []),
            Arr::get($order, 'data.order_items', []),
            Arr::get($order, 'products', []),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && count($candidate) > 0) {
                return collect($candidate)->filter(fn ($row) => is_array($row));
            }
        }

        return collect();
    }

    private function extractOrderDetailRow(mixed $json): ?array
    {
        $candidates = [
            Arr::get($json, 'data.order'),
            Arr::get($json, 'data.orders'),
            Arr::get($json, 'data'),
            $json,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate)) {
                if (array_is_list($candidate)) {
                    $first = $candidate[0] ?? null;
                    if (is_array($first)) {
                        return $first;
                    }
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    private function normalizeAddressText(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '—';
        }

        if ((str_starts_with($address, '{') && str_ends_with($address, '}')) || (str_starts_with($address, '[') && str_ends_with($address, ']'))) {
            $decoded = json_decode($address, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $fullAddress = trim((string) Arr::get($decoded, 'full_address', ''));
                if ($fullAddress !== '') {
                    return $fullAddress;
                }

                $cityName = trim((string) Arr::get($decoded, 'city.name', ''));
                $street = trim((string) Arr::get($decoded, 'address', ''));
                $parts = array_values(array_filter([$cityName, $street], fn ($p) => $p !== ''));
                if (!empty($parts)) {
                    return implode(' - ', $parts);
                }
            }
        }

        return $address;
    }

    private function authenticatedClient(bool $withoutVerify = false)
    {
        $username = (string) config('services.ariya_crm.username', self::USERNAME);
        $password = (string) config('services.ariya_crm.password', self::PASSWORD);
        if (trim($password) === '') {
            $password = self::PASSWORD;
        }

        try {
            $verifySsl = (bool) config('services.ariya_crm.verify_ssl', true);
            $shouldDisableVerify = $withoutVerify || !$verifySsl || $this->sslVerifyDisabledForRuntime;

            $client = Http::asForm()->timeout(45)->connectTimeout(15)->withOptions(['allow_redirects' => false]);
            if ($shouldDisableVerify) {
                $client = $client->withoutVerifying();
            }

            $login = null;
            $lastException = null;
            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                try {
                    $login = $client->post(self::LOGIN_URL, ['username' => $username, 'password' => $password]);
                    break;
                } catch (Throwable $ex) {
                    $lastException = $ex;
                    if ($attempt < $this->maxAttempts) {
                        usleep($attempt * 400000);
                    }
                }
            }

            if (!$login && $lastException) {
                throw $lastException;
            }
            if (!$login->successful()) {
                Log::warning('Ariya login failed for order import', ['status' => $login->status(), 'location' => $login->header('Location')]);
                $this->lastError = 'ورود به API ناموفق بود. HTTP ' . $login->status();
                return null;
            }

            $token = Arr::get($login->json(), 'token')
                ?: Arr::get($login->json(), 'access_token')
                ?: Arr::get($login->json(), 'data.token');

            $authed = $client->withCookies($login->cookies()->toArray(), 'api.ariyajanebi.ir');
            if ($token) {
                $authed = $authed->withToken((string) $token)->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'X-API-TOKEN' => $token,
                ]);
            }

            return $authed;
        } catch (\Throwable $e) {
            if (!$withoutVerify && str_contains($e->getMessage(), 'cURL error 77')) {
                $this->sslVerifyDisabledForRuntime = true;
                $this->logSslFallbackWarningOnce();
                return $this->authenticatedClient(true);
            }

            if (str_contains($e->getMessage(), 'cURL error 28')) {
                $this->lastError = 'timeout در اتصال به API. لطفاً مجدداً تلاش کنید.';
            } elseif (str_contains($e->getMessage(), 'cURL error 35')) {
                $this->lastError = 'اتصال شبکه به API قطع شد (connection reset). لطفاً دوباره تلاش کنید.';
            } elseif (str_contains($e->getMessage(), 'Resolving timed out')) {
                $this->lastError = 'DNS در اتصال به API timeout شد. اینترنت/ DNS سرور را بررسی کنید.';
            }

            Log::error('Ariyajanebi order import auth exception', ['message' => $e->getMessage(), 'without_verify' => $withoutVerify]);
            if (!$this->lastError) {
                $this->lastError = 'خطا در ارتباط با API: ' . mb_substr($e->getMessage(), 0, 180);
            }
            return null;
        }
    }

    private function sendWithRetry($client, string $url, ?int $attempts = null)
    {
        $response = null;
        $attempts = max(1, $attempts ?? $this->maxAttempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $client->get($url);
                return $response;
            } catch (Throwable $e) {
                Log::warning('Ariya API request attempt failed', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt >= $attempts) {
                    $this->lastError = 'خطای شبکه در ارتباط با API: ' . mb_substr($e->getMessage(), 0, 120);
                    return null;
                }

                usleep($attempt * 400000);
            }
        }

        return $response;
    }

    private function logSslFallbackWarningOnce(): void
    {
        $ttlSeconds = (int) config('services.ariya_crm.ssl_warning_ttl_seconds', 43200);
        if ($ttlSeconds < 60) {
            $ttlSeconds = 60;
        }

        if (Cache::add(self::SSL_WARNING_CACHE_KEY, 1, now()->addSeconds($ttlSeconds))) {
            Log::warning('Ariyajanebi SSL cert issue detected in order import, retrying without SSL verification. Set ARIYA_CRM_VERIFY_SSL=false to stop this warning.');
        }
    }
}
