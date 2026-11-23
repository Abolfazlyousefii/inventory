<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Only for the disposable MariaDB 10.11 GitHub Actions service.
 * Explicit environment guards prevent accidental execution on a real server.
 */
if (
    getenv('CI') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'stocktake_snapshot_ci'
) {
    fwrite(STDERR, "Refusing to run outside disposable MariaDB CI.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$check(DB::connection()->getDriverName() === 'mysql', 'Not a MySQL/MariaDB connection');
$check(!Schema::hasTable('stock_count_document_items'), 'The CI fixture database must be empty');

Schema::create('stock_count_document_items', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('document_id');
    $table->unsignedBigInteger('product_variant_id')->nullable();
    $table->integer('actual_quantity')->nullable();
});

DB::table('stock_count_document_items')->insert([
    ['id' => 1, 'document_id' => 209, 'product_variant_id' => 1, 'actual_quantity' => 0],
    ['id' => 2, 'document_id' => 210, 'product_variant_id' => 2, 'actual_quantity' => 6],
    ['id' => 3, 'document_id' => 211, 'product_variant_id' => 3, 'actual_quantity' => 0],
    ['id' => 4, 'document_id' => 212, 'product_variant_id' => 4, 'actual_quantity' => 1],
    ['id' => 5, 'document_id' => 300, 'product_variant_id' => 5, 'actual_quantity' => null],
]);

$oldCounts = static fn(): string => DB::table('stock_count_document_items')
    ->orderBy('id')->get(['id', 'document_id', 'product_variant_id', 'actual_quantity'])->toJson();
$before = $oldCounts();

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_29_000001_add_bundle_snapshots_to_stock_count_items.php';
$migration->up();

foreach (['bundle_free_at_start', 'bundle_reserved_at_start', 'site_held_at_start'] as $field) {
    $check(Schema::hasColumn('stock_count_document_items', $field), 'Missing column: '.$field);
    $check((int) DB::table('stock_count_document_items')->where('id', 1)->value($field) === 0,
        'Legacy row must have neutral new column: '.$field);
}
$check($oldCounts() === $before, 'Migration changed historical actual quantities');

DB::table('stock_count_document_items')->where('id', 2)->update([
    'bundle_free_at_start' => 7,
    'bundle_reserved_at_start' => 4,
    'site_held_at_start' => 2,
]);
$after = $oldCounts();
$migration->up();
$check($oldCounts() === $after, 'Idempotent replay changed legacy counts');
foreach (['bundle_free_at_start'=>7, 'bundle_reserved_at_start'=>4, 'site_held_at_start'=>2] as $field=>$expected) {
    $check((int) DB::table('stock_count_document_items')->where('id', 2)->value($field) === $expected,
        'Idempotent replay altered existing protected snapshot: '.$field);
}
$migration->down();
$check($oldCounts() === $before, 'Non-destructive rollback changed historical counts');
$check(DB::table('stock_count_document_items')->where('id', 5)->value('actual_quantity') === null,
    'Null/unresolved count was coerced to zero');

echo "PASS: MariaDB 10.11 snapshot migration preserves historical counts, explicit zeros and idempotence.\n";
