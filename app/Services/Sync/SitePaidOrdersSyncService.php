<?php

namespace App\Services\Sync;

use App\Models\Invoice;
use App\Models\Site\Order as SiteOrder;
use App\Services\ExternalPaidOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bounded, direct MySQL import of paid Site orders; no HTTP/SSL/API token.
 * The existing ExternalPaidOrderService retains all invoice/hold idempotency logic.
 */
class SitePaidOrdersSyncService
{
    private const BATCH = 5;
    // Always include the newest paid orders when reconciling on Inventory page views.
    // Older rejected imports are still retried in a bounded cursor-driven batch.
    private const RECENT_BATCH = 2;

    public function __construct(private readonly ExternalPaidOrderService $orderService) {}

    public function syncAll(): array
    {
        // Rollback safety: installing the files does not silently switch the
        // existing paid-order importer until the direct mode is explicitly on.
        if (!config('services.request_driven_site_stock', false)) {
            return app(LegacyApiSitePaidOrdersSyncService::class)->syncAll();
        }
        $summary = [
            'received' => 0, 'created' => 0, 'existing' => 0,
            'skipped' => 0, 'invalid' => 0, 'errors' => [],
        ];

        try {
            $this->assertSharedMysql();
            $inventoryDb = DB::connection()->getDatabaseName();
            // Quoted schema identifier is drawn from Laravel config, never request input.
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $inventoryDb)) {
                throw new RuntimeException('Unsafe Inventory schema name.');
            }

            // Select ONLY paid orders without an Inventory invoice. No full-site HTTP export,
            // and no growing in-memory list of previously processed order IDs.
            $cursor = (int) (DB::table('site_paid_order_import_cursor')->where('id', 1)->value('last_order_id') ?? 0);
            // New paid orders take priority, even if old paid orders have bad
            // seller/customer mappings. All queries are bounded and omit invoices
            // already created in Inventory (the importer is independently idempotent).
            $findBatch = static function (int $afterId, int $limit, bool $newestFirst = false, array $exclude = []) use ($inventoryDb) {
                $query = DB::connection('site')->table('orders as site_orders')
                    ->leftJoin($inventoryDb.'.invoices as inventory_invoices',
                        'inventory_invoices.external_order_id', '=', 'site_orders.id')
                    ->where('site_orders.status', 'paid')
                    ->where('site_orders.id', '>', $afterId)
                    ->whereNull('inventory_invoices.id');
                if ($exclude !== []) {
                    $query->whereNotIn('site_orders.id', $exclude);
                }
                return $query->orderBy('site_orders.id', $newestFirst ? 'desc' : 'asc')
                    ->limit($limit)
                    ->pluck('site_orders.id');
            };

            $recentIds = $findBatch(0, self::RECENT_BATCH, true);
            $olderLimit = self::BATCH - $recentIds->count();
            $olderIds = $findBatch($cursor, $olderLimit, false, $recentIds->all());
            if ($olderIds->isEmpty() && $cursor > 0) {
                // Wrap around for older failed orders; do not skip them permanently.
                DB::table('site_paid_order_import_cursor')->where('id', 1)
                    ->update(['last_order_id' => 0]);
                $olderIds = $findBatch(0, $olderLimit, false, $recentIds->all());
            }
            $ids = $recentIds->concat($olderIds)->unique()->take(self::BATCH);
            if ($ids->isEmpty()) return $summary;


            $orders = SiteOrder::query()
                ->whereIn('id', $ids->all())->where('status', 'paid')
                ->with(['user:id,first_name,last_name,username',
                    'items.product:id,type,title,product_code',
                    'items.get_price:id,variant_code'])
                ->orderBy('id')->get();

            // Site schema contains province/city titles. Load only the ones needed by this batch.
            $provinces = DB::connection('site')->table('provinces')
                ->whereIn('id', $orders->pluck('province_id')->filter()->unique()->all())
                ->pluck('name', 'id');
            $cities = DB::connection('site')->table('cities')
                ->whereIn('id', $orders->pluck('city_id')->filter()->unique()->all())
                ->pluck('name', 'id');

            foreach ($orders as $order) {
                $summary['received']++;
                try {
                    $payload = $this->toImportPayload($order, $provinces, $cities);
                    $result = $this->orderService->import($payload, false);
                    $summary[$result['created'] ? 'created' : 'existing']++;
                } catch (Throwable $exception) {
                    // Never advance past or mark a failed order: later invocations retry it.
                    $summary['invalid']++;
                    $summary['errors'][] = ['order_id' => $order->id, 'message' => $exception->getMessage()];
                    Log::warning('Direct Site paid-order import failed.', [
                        'order_id' => $order->id, 'exception' => $exception::class,
                    ]);
                }
            }
            // Only the chronological/retry batch advances the historical cursor;
            // processing a new order must not skip pending older orders.
            if ($olderIds->isNotEmpty()) {
                DB::table('site_paid_order_import_cursor')->where('id', 1)
                    ->update(['last_order_id' => (int) $olderIds->max()]);
            }
        } catch (Throwable $exception) {
            report($exception);
            $summary['errors'][] = ['message' => $exception->getMessage()];
        }

        return $summary;
    }

    /**
     * Import exactly one paid Site order. Administrative recovery method:
     * does not move the cursor or import other historical orders.
     * The canonical Invoice service provides transactional/idempotent creation.
     *
     * @return array{site_order_id:int,invoice_id:int,created:bool}
     */
    public function importOne(int $siteOrderId): array
    {
        if (! config('services.request_driven_site_stock', false)) {
            throw new RuntimeException('Enable REQUEST_DRIVEN_SITE_STOCK before direct invoice import.');
        }
        if ($siteOrderId < 1) {
            throw new RuntimeException('Invalid Site order ID.');
        }
        $this->assertSharedMysql();

        $order = SiteOrder::query()
            ->whereKey($siteOrderId)->where('status', 'paid')
            ->with(['user:id,first_name,last_name,username',
                'items.product:id,type,title,product_code',
                'items.get_price:id,variant_code'])
            ->first();
        if (! $order) {
            throw new RuntimeException('Site order does not exist or is not paid.');
        }

        $provinces = DB::connection('site')->table('provinces')
            ->where('id', $order->province_id)->pluck('name', 'id');
        $cities = DB::connection('site')->table('cities')
            ->where('id', $order->city_id)->pluck('name', 'id');

        $result = $this->orderService->import(
            $this->toImportPayload($order, $provinces, $cities), false
        );

        return [
            'site_order_id' => (int) $order->id,
            'invoice_id' => (int) $result['invoice']->id,
            'created' => (bool) $result['created'],
        ];
    }

    private function toImportPayload(SiteOrder $order, $provinces, $cities): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $isPhysical = !$item->product || $item->product->isPhysical();
            $realPrice = max(0, (int) $item->real_price);
            $unitPrice = max(0, (int) $item->price);
            $lineDiscount = max(0, $item->originalUnitPrice() - $item->finalUnitPrice())
                * max(0, (int) $item->quantity);
            $items[] = [
                'product_id' => $item->product_id,
                'price_id' => $item->price_id,
                'inventory_variant_id' => $item->price_id,
                'is_physical' => $isPhysical,
                'title' => $item->product?->title ?: $item->title,
                'variant_code' => $item->get_price?->variant_code,
                'stock_code' => null,
                'quantity' => max(0, (int) $item->quantity),
                // Site order snapshots are in toman; Inventory records rials.
                'price' => ($item->real_price !== null ? $realPrice : $unitPrice) * 10,
                // Product/bundle discount is a monetary line amount, not a percent.
                // The remaining order discount (e.g. coupon) is an invoice discount.
                'discount_amount' => $lineDiscount * 10,
            ];
        }
        if (!$items) throw new RuntimeException('Paid Site order has no items.');
        $name = trim((string) ($order->name ?? ''));
        if ($name === '') $name = trim((string) ($order->user?->first_name.' '.$order->user?->last_name));

        return [
            'crm_order_id' => (int) $order->id,
            'occurred_at' => $order->created_at,
            'user' => [
                'id' => $order->user_id,
                // This is the Ariya Site customer ID, NOT a CRM customer ID.
                'site_user_id' => $order->user_id,
                'name' => $name,
                'mobile' => $order->mobile,
                'username' => $order->user?->username,
            ],
            'shipping_address' => [
                'name' => $name,
                'mobile' => $order->mobile,
                'province' => ['name' => $provinces->get($order->province_id)],
                'city' => ['name' => $cities->get($order->city_id)],
                'postal_code' => $order->postal_code,
                'address' => $order->address,
            ],
            'order' => [
                'id' => (int) $order->id,
                'stock_hold_id' => max(0, (int) $order->inventory_stock_hold_id),
                'shipping_price' => max(0, (int) $order->shipping_cost) * 10,
                'discount_amount' => max(0, (int) $order->discount_amount) * 10,
                'total' => max(0, (int) $order->price) * 10,
            ],
            'items' => $items,
        ];
    }

    private function assertSharedMysql(): void
    {
        $inv = DB::connection();
        $site = DB::connection('site');
        if ($inv->getDriverName() !== 'mysql' || $site->getDriverName() !== 'mysql') {
            throw new RuntimeException('Direct paid-order import requires MySQL on both connections.');
        }
        if ($site->getDatabaseName() === $inv->getDatabaseName()) {
            throw new RuntimeException('Site and Inventory must use distinct MySQL schemas.');
        }
        // MySQL cross-database joins are only safe when the two connections resolve
        // to the SAME instance. Fail closed when host, port or Unix socket differs.
        foreach (['host', 'port', 'unix_socket'] as $key) {
            if ((string) config('database.connections.mysql.'.$key) !== (string) config('database.connections.site.'.$key)) {
                throw new RuntimeException('Site and Inventory databases must be on the same MySQL server.');
            }
        }
    }
}
