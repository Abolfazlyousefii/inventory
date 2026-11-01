<?php
/**
 * INV-05 forensic inventory/price trace: read-only database access.
 * Run from your Laravel project root: php inv05-trace-price-origin.php
 * Input: storage/app/private/reports/inv05-quarantine-preview/zero-price-quarantine.csv
 * Output: storage/app/private/reports/inv05-root-cause/
 */

declare(strict_types=1);

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$input = storage_path('app/private/reports/inv05-quarantine-preview/zero-price-quarantine.csv');
$outDir = storage_path('app/private/reports/inv05-root-cause');
if (!is_file($input)) {
    fwrite(STDERR, "Missing input report: {$input}\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: {$outDir}\n");
    exit(1);
}

$inputFile = fopen($input, 'rb');
if (!$inputFile) {
    throw new RuntimeException('Could not read zero-price variant report.');
}
$columns = fgetcsv($inputFile);
if (!is_array($columns)) {
    throw new RuntimeException('Empty input CSV.');
}
$columns = array_map(static fn ($x): string => trim((string) $x, "\xEF\xBB\xBF \t\r\n"), $columns);
foreach (['product_id', 'variant_id', 'product_name'] as $required) {
    if (!in_array($required, $columns, true)) {
        throw new RuntimeException("Input CSV missing {$required} column.");
    }
}
$rowsById = [];
while (($record = fgetcsv($inputFile)) !== false) {
    if (count($record) !== count($columns)) {
        continue;
    }
    $row = array_combine($columns, $record);
    $id = (int) ($row['variant_id'] ?? 0);
    if ($id > 0) {
        $rowsById[$id] = $row;
    }
}
fclose($inputFile);
if (!$rowsById) {
    throw new RuntimeException('No variant IDs found in the input CSV.');
}
$ids = array_keys($rowsById);

if (!Schema::hasTable('product_variants') || !Schema::hasTable('products')) {
    throw new RuntimeException('Product tables are missing. Check your database connection.');
}

$variantColumns = ['v.id', 'v.product_id', 'v.sell_price', 'v.created_at', 'v.updated_at',
    'v.is_active', 'v.sales_enabled', 'p.is_sellable as product_is_sellable', 'p.price as parent_price', 'p.created_at as product_created_at'];
if (Schema::hasColumn('products', 'uniform_sale_price')) {
    $variantColumns[] = 'p.uniform_sale_price';
}
if (Schema::hasColumn('products', 'models')) {
    $variantColumns[] = 'p.models as product_models';
}
$variantData = DB::table('product_variants as v')
    ->join('products as p', 'p.id', '=', 'v.product_id')
    ->whereIn('v.id', $ids)->select($variantColumns)->get()->keyBy('id');

$eventFile = fopen($outDir . '/price-events.csv', 'wb');
if (!$eventFile) {
    throw new RuntimeException('Cannot write event report.');
}
fputcsv($eventFile, ['variant_id','activity_log_id','timestamp','actor_user_id',
    'action','previous_sell_price','new_sell_price','interpretation']);

$trace = [];
foreach ($ids as $id) {
    $trace[$id] = ['created_price' => null, 'created_event_id' => null,
        'last_event' => null, 'reset_events' => 0, 'positive_events' => 0,
        'total_price_events' => 0, 'inbound_count' => 0, 'purchase_receipts' => 0,
        'returns_inbound' => 0, 'first_in_at' => null, 'first_in_reason' => null,
        'first_in_reference' => null, 'last_in_at' => null, 'last_in_reason' => null];
}

// ActivityObserver records original and changed values on Eloquent updates.
// Missing events do not establish whether a price was previously set.
if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'properties')) {
    DB::table('activity_logs')
        ->where('subject_type', 'App\\Models\\ProductVariant')
        ->whereIn('subject_id', $ids)
        ->whereIn('action', ['created', 'updated'])
        ->select(['id', 'subject_id', 'user_id', 'action', 'properties', 'occurred_at'])
        ->orderBy('id')
        ->chunkById(500, function ($logs) use (&$trace, $eventFile): void {
            foreach ($logs as $log) {
                $id = (int) $log->subject_id;
                $properties = json_decode((string) ($log->properties ?? ''), true);
                if (!is_array($properties)) {
                    continue;
                }
                $before = null;
                $after = null;
                $label = '';
                if ($log->action === 'created' && array_key_exists('sell_price', $properties['attributes'] ?? [])) {
                    $after = (int) $properties['attributes']['sell_price'];
                    $trace[$id]['created_price'] = $after;
                    $trace[$id]['created_event_id'] = (int) $log->id;
                    $label = $after <= 0 ? 'created_with_zero_price' : 'created_with_positive_price';
                } elseif ($log->action === 'updated' && array_key_exists('sell_price', $properties['changes'] ?? [])) {
                    $after = (int) $properties['changes']['sell_price'];
                    if (isset($properties['original']['sell_price'])) {
                        $before = (int) $properties['original']['sell_price'];
                    }
                    $label = $before !== null && $before > 0 && $after <= 0
                        ? 'positive_price_reset_to_zero'
                        : ($after <= 0 ? 'updated_to_zero' : 'updated_to_positive');
                } else {
                    continue;
                }
                $trace[$id]['total_price_events']++;
                if ($before !== null && $before > 0 && $after <= 0) {
                    $trace[$id]['reset_events']++;
                }
                if ($after !== null && $after > 0) {
                    $trace[$id]['positive_events']++;
                }
                $trace[$id]['last_event'] = [
                    'id' => (int) $log->id,
                    'date' => (string) $log->occurred_at,
                    'action' => (string) $log->action,
                    'before' => $before,
                    'after' => $after,
                    'actor' => $log->user_id,
                    'label' => $label,
                ];
                fputcsv($eventFile, [$id, $log->id, $log->occurred_at,
                    $log->user_id, $log->action, $before, $after, $label]);
            }
        }, 'id');
}
fclose($eventFile);

// Stock movements tell us how inventory entered the system, not why prices changed.
if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'product_variant_id')) {
    DB::table('stock_movements')
        ->whereIn('product_variant_id', $ids)
        ->where('type', 'in')
        ->where('quantity', '>', 0)
        ->select(['id', 'product_variant_id', 'reason', 'reference_type', 'reference_id', 'created_at'])
        ->orderBy('id')
        ->chunkById(500, function ($movements) use (&$trace): void {
            foreach ($movements as $m) {
                $id = (int) $m->product_variant_id;
                $t = &$trace[$id];
                $t['inbound_count']++;
                if ((string) $m->reason === 'purchase' || str_contains((string) $m->reason, 'purchase')) {
                    $t['purchase_receipts']++;
                }
                if ((string) $m->reason === 'return' || str_contains((string) $m->reason, 'return')) {
                    $t['returns_inbound']++;
                }
                if ($t['first_in_at'] === null) {
                    $t['first_in_at'] = (string) $m->created_at;
                    $t['first_in_reason'] = (string) $m->reason;
                    $t['first_in_reference'] = (string) ($m->reference_type ?? '') . '#' . (string) ($m->reference_id ?? '');
                }
                $t['last_in_at'] = (string) $m->created_at;
                $t['last_in_reason'] = (string) $m->reason;
                unset($t);
            }
        }, 'id');
}

$purchaseQty = [];
$purchasePriceHistory = [];
if (Schema::hasTable('purchase_items') && Schema::hasColumn('purchase_items', 'product_variant_id')) {
    $q = DB::table('purchase_items')->whereIn('product_variant_id', $ids);
    if (Schema::hasColumn('purchase_items', 'quantity')) {
        $purchaseQty = (clone $q)->select('product_variant_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_variant_id')->pluck('qty', 'product_variant_id')->all();
    }
    if (Schema::hasColumn('purchase_items', 'sell_price')) {
        $purchasePriceHistory = (clone $q)->where('sell_price', '>', 0)
            ->select('product_variant_id', DB::raw('COUNT(*) as count_positive'), DB::raw('MAX(sell_price) as max_snapshot'))
            ->groupBy('product_variant_id')->get()->keyBy('product_variant_id')->all();
    }
}

$appliedPriceDocs = [];
if (Schema::hasTable('price_change_documents') && Schema::hasTable('price_change_document_items')) {
    $appliedPriceDocs = DB::table('price_change_document_items as i')
        ->join('price_change_documents as d', 'd.id', '=', 'i.price_change_document_id')
        ->whereIn('i.product_variant_id', $ids)
        ->where('d.status', 'applied')
        ->select('i.product_variant_id', 'i.old_price', 'i.new_price', 'd.id as document_id', 'd.applied_at')
        ->orderBy('d.id')
        ->get()->groupBy('product_variant_id')->all();
}

$report = fopen($outDir . '/root-cause-82.csv', 'wb');
if (!$report) {
    throw new RuntimeException('Cannot create root-cause report.');
}
$heading = ['variant_id','product_id','product_name','central_stock','other_stock','current_sell_price',
    'product_master_price','parent_summary_price','product_is_sellable','variant_created_at','variant_updated_at',
    'creation_logged_price','creation_log_id','last_logged_price_at','last_logged_price_before',
    'last_logged_price_after','last_price_actor_user_id','last_price_log_id','price_reset_count',
    'positive_price_event_count','price_event_count','first_inbound_at','first_inbound_reason',
    'first_inbound_reference','inbound_movement_count','purchase_inbound_count','return_inbound_count',
    'purchase_qty_recorded','positive_purchase_snapshots','max_purchase_snapshot_price',
    'applied_price_document_count','latest_applied_document_id','latest_applied_price',
    'inline_sales_return_product','diagnosis','notes'];
fputcsv($report, $heading);
$counts = [];
foreach ($rowsById as $id => $source) {
    $v = $variantData->get($id);
    $t = $trace[$id];
    $event = $t['last_event'];
    $docRows = $appliedPriceDocs[$id] ?? collect();
    $latestDoc = $docRows->last();
    $purchase = $purchasePriceHistory[$id] ?? null;
    $current = $v === null ? null : (int) $v->sell_price;
    $modelData = $v ? json_decode((string) ($v->product_models ?? ''), true) : null;
    $isSalesReturn = is_array($modelData) && !empty($modelData['sales_return_inline']);
    if ($v === null) {
        $diagnosis = 'MISSING_VARIANT_OR_PRODUCT';
    } elseif ($current > 0) {
        $diagnosis = 'ALREADY_PRICED_SINCE_BASELINE';
    } elseif ($event && $event['before'] !== null && $event['before'] > 0 && $event['after'] <= 0) {
        $diagnosis = 'CONFIRMED_PRICE_RESET_IN_ACTIVITY_LOG';
    } elseif ($event && $event['action'] === 'created' && $event['after'] <= 0) {
        $diagnosis = 'CREATED_AT_ZERO_NO_LATER_LOGGED_PRICE_CHANGE';
    } elseif ($event && $event['after'] <= 0 && $t['positive_events'] === 0) {
        $diagnosis = 'ZERO_PRICE_IN_LOGS_ORIGIN_INCOMPLETE';
    } elseif ($event && $event['after'] > 0) {
        $diagnosis = 'POSITIVE_LAST_LOG_BUT_CURRENT_ZERO_AUDIT_GAP';
    } elseif ($event && $event['after'] <= 0) {
        $diagnosis = 'PRICE_ENDED_ZERO_PRIOR_HISTORY_MIXED';
    } else {
        $diagnosis = 'NO_PRICE_AUDIT_EVIDENCE';
    }
    $counts[$diagnosis] = ($counts[$diagnosis] ?? 0) + 1;
    $notes = [];
    if ($isSalesReturn) $notes[] = 'Product tagged sales_return_inline';
    if ($v && isset($v->uniform_sale_price) && (int) $v->uniform_sale_price > 0 && $current === 0) {
        $notes[] = 'Positive product master price conflicts with zero variant price';
    }
    if ($t['inbound_count'] === 0) $notes[] = 'No inbound stock_movements found (warehouse stock may predate logs/import)';
    if ($t['total_price_events'] === 0) $notes[] = 'No actor/history for price; cannot assign precise cause';
    if ($v && $t['first_in_at'] && (string) $v->created_at > $t['first_in_at']) {
        $notes[] = 'Stock event appears before variant creation; verify timestamps';
    }
    $data = [
        'variant_id' => $id,
        'product_id' => $source['product_id'],
        'product_name' => $source['product_name'],
        'central_stock' => $source['central_stock'] ?? '',
        'other_stock' => $source['non_central_stock'] ?? '',
        'current_sell_price' => $current,
        'product_master_price' => $v->uniform_sale_price ?? '',
        'parent_summary_price' => $v->parent_price ?? '',
        'product_is_sellable' => $v === null ? '' : ((int) $v->product_is_sellable),
        'variant_created_at' => $v->created_at ?? '',
        'variant_updated_at' => $v->updated_at ?? '',
        'creation_logged_price' => $t['created_price'] ?? '',
        'creation_log_id' => $t['created_event_id'] ?? '',
        'last_logged_price_at' => $event['date'] ?? '',
        'last_logged_price_before' => $event['before'] ?? '',
        'last_logged_price_after' => $event['after'] ?? '',
        'last_price_actor_user_id' => $event['actor'] ?? '',
        'last_price_log_id' => $event['id'] ?? '',
        'price_reset_count' => $t['reset_events'],
        'positive_price_event_count' => $t['positive_events'],
        'price_event_count' => $t['total_price_events'],
        'first_inbound_at' => $t['first_in_at'] ?? '',
        'first_inbound_reason' => $t['first_in_reason'] ?? '',
        'first_inbound_reference' => $t['first_in_reference'] ?? '',
        'inbound_movement_count' => $t['inbound_count'],
        'purchase_inbound_count' => $t['purchase_receipts'],
        'return_inbound_count' => $t['returns_inbound'],
        'purchase_qty_recorded' => $purchaseQty[$id] ?? 0,
        'positive_purchase_snapshots' => $purchase->count_positive ?? 0,
        'max_purchase_snapshot_price' => $purchase->max_snapshot ?? '',
        'applied_price_document_count' => $docRows->count(),
        'latest_applied_document_id' => $latestDoc->document_id ?? '',
        'latest_applied_price' => $latestDoc->new_price ?? '',
        'inline_sales_return_product' => $isSalesReturn ? 'yes' : 'no',
        'diagnosis' => $diagnosis,
        'notes' => implode(' | ', $notes),
    ];
    fputcsv($report, array_map(fn ($key) => $data[$key] ?? '', $heading));
}
fclose($report);
$summary = [
    'analyzed_variants' => count($rowsById),
    'matched_current_variants' => $variantData->count(),
    'diagnosis_counts' => $counts,
    'database_changed' => false,
    'interpretation' => 'Activity-log evidence is not complete for direct SQL writes, imports, disabled observers, or old/removed logs. A diagnosis is not proof of the exact code path without correlated logs/deploy history.',
    'paths' => ['root_cause' => $outDir . '/root-cause-82.csv', 'price_events' => $outDir . '/price-events.csv'],
];
file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
