<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PriceIntegrityAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
        Storage::fake('local');
    }

    public function test_positive_stock_zero_variant_is_critical_and_invoice_suggests_high_confidence(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity --format=csv')->assertExitCode(0);
        $anomalies = $this->csv('reports/price-integrity/anomalies.csv');
        $suggestions = $this->csv('reports/price-integrity/suggestions.csv');
        $summary = $this->summary();

        $a02 = $this->where($anomalies, 'anomaly_code', 'A02')[0] ?? null;
        $a02Suggestion = $this->where($suggestions, 'anomaly_code', 'A02')[0] ?? null;

        $this->assertNotNull($a02);
        $this->assertSame('Critical', $a02['severity']);
        $this->assertSame('5', $a02['warehouse_stock']);
        $this->assertNotSame('', $a02['product_name']);
        $this->assertNotSame('', $a02['variant_name']);
        $this->assertSame('0', $a02['variant_sell_price']);
        $this->assertSame('900', $a02['last_positive_sale_price']);
        $this->assertNotNull($a02Suggestion);
        $this->assertSame('900', $a02Suggestion['suggested_price']);
        $this->assertSame('High', $a02Suggestion['confidence']);
        $this->assertGreaterThan(0, $summary['positive_warehouse_stock']);
        $this->assertGreaterThan(0, $summary['sellable_zero_price']);
        $this->assertFalse($summary['data_changed']);
    }

    public function test_inactive_unstocked_zero_variant_is_low(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity')->assertExitCode(0);
        $this->assertSame('Low', $this->where($this->csv('reports/price-integrity/anomalies.csv'), 'anomaly_code', 'A07')[0]['severity']);
    }

    public function test_product_summary_desync_is_reported(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity')->assertExitCode(0);
        $codes = array_column($this->csv('reports/price-integrity/anomalies.csv'), 'anomaly_code');
        $this->assertContains('A08', $codes);
        $this->assertContains('A09', $codes);
        $this->assertGreaterThanOrEqual(2, $this->summary()['product_summary_desync']);
    }

    public function test_buy_price_does_not_create_sell_price_suggestion(): void
    {
        DB::table('products')->insert(['id' => 9, 'name' => 'Buy only', 'sku' => 'P9', 'code' => 'P9', 'price' => 0, 'stock' => 0, 'reserved' => 0, 'is_sellable' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_variants')->insert(['id' => 90, 'product_id' => 9, 'variant_name' => 'V90', 'variant_code' => 'V90', 'sell_price' => 0, 'buy_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => 1, 'sales_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_items')->insert(['purchase_id' => null, 'product_id' => 9, 'product_variant_id' => 90, 'product_name' => 'Buy only', 'product_code' => 'P9', 'quantity' => 2, 'buy_price' => 1000, 'sell_price' => 0, 'line_total' => 2000, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('inventory:audit-price-integrity')->assertExitCode(0);
        $row = collect($this->csv('reports/price-integrity/suggestions.csv'))->firstWhere('variant_id', '90');
        $this->assertSame('None', $row['confidence']);
        $this->assertSame('1', $row['manual_pricing_required']);
    }

    public function test_invoice_and_preinvoice_zero_items_are_critical_and_preserve_updated_at(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity --format=json')->assertExitCode(0);
        $codes = collect($this->json('reports/price-integrity/anomalies.json'))->keyBy('anomaly_code');
        $this->assertSame('Critical', $codes['A05']['severity']);
        $this->assertSame('Critical', $codes['A06']['severity']);
        $this->assertSame('2026-01-02 03:04:05', $codes['A05']['updated_at']);
        $this->assertSame('2026-01-03 04:05:06', $codes['A06']['updated_at']);
    }

    public function test_no_write_queries_are_executed_by_command(): void
    {
        $this->seedBase();
        $queries = [];
        DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });
        $this->artisan('inventory:audit-price-integrity')->assertExitCode(0);
        $this->assertFalse(collect($queries)->contains(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke)\b/i', $sql)));
    }


    public function test_write_query_guard_blocks_insert_before_execution(): void
    {
        Schema::create('guard_probe_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        [$install, $disable, $command] = $this->guardReflection();

        try {
            $install->invoke($command);

            $this->expectException(\RuntimeException::class);
            DB::insert('/* audit guard */ insert into guard_probe_rows (name) values (?)', ['blocked']);
        } finally {
            $disable->invoke($command);
            $this->assertSame(0, DB::table('guard_probe_rows')->count());
        }
    }

    public function test_write_query_guard_blocks_update_before_execution(): void
    {
        Schema::create('guard_update_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('guard_update_rows')->insert(['name' => 'original']);

        [$install, $disable, $command] = $this->guardReflection();

        try {
            $install->invoke($command);

            $this->expectException(\RuntimeException::class);
            DB::update('update guard_update_rows set name = ? where name = ?', ['changed', 'original']);
        } finally {
            $disable->invoke($command);
            $this->assertSame('original', DB::table('guard_update_rows')->value('name'));
        }
    }

    public function test_write_query_guard_blocks_write_cte_before_execution(): void
    {
        Schema::create('guard_cte_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        [$install, $disable, $command] = $this->guardReflection();

        try {
            $install->invoke($command);

            $this->expectException(\RuntimeException::class);
            DB::statement("with probe as (select 'delete is text' as label) /* update in comment */ insert into guard_cte_rows (name) values ('blocked')");
        } finally {
            $disable->invoke($command);
            $this->assertSame(0, DB::table('guard_cte_rows')->count());
        }
    }

    public function test_write_query_guard_allows_select_and_select_cte_then_disable_allows_normal_writes(): void
    {
        Schema::create('guard_select_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('guard_select_rows')->insert(['name' => 'allowed']);

        [$install, $disable, $command] = $this->guardReflection();

        try {
            $install->invoke($command);

            $this->assertSame('allowed', DB::selectOne('select name from guard_select_rows where name = ?', ['allowed'])->name);
            $this->assertSame('UPDATE text only', DB::selectOne("with probe as (select 'UPDATE text only' as label) /* delete in comment */ select label from probe")->label);
        } finally {
            $disable->invoke($command);
        }

        DB::table('guard_select_rows')->insert(['name' => 'after-disable']);
        $this->assertSame(2, DB::table('guard_select_rows')->count());
    }

    public function test_write_query_guard_registers_again_for_new_connection_after_purge(): void
    {
        Schema::create('guard_reconnect_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        [$install, $disable, $command] = $this->guardReflection();
        $install->invoke($command);
        $disable->invoke($command);

        DB::purge('sqlite');
        Schema::create('guard_reconnect_rows', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        try {
            $install->invoke($command);

            $this->expectException(\RuntimeException::class);
            DB::insert('insert into guard_reconnect_rows (name) values (?)', ['blocked-new-connection']);
        } finally {
            $disable->invoke($command);
            $this->assertSame(0, DB::table('guard_reconnect_rows')->count());
        }
    }

    public function test_csv_and_json_outputs_are_written(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity --format=csv')->assertExitCode(0);
        Storage::disk('local')->assertExists('reports/price-integrity/anomalies.csv');
        $this->artisan('inventory:audit-price-integrity --format=json')->assertExitCode(0);
        Storage::disk('local')->assertExists('reports/price-integrity/anomalies.json');
    }

    public function test_product_variant_and_severity_filters_work(): void
    {
        $this->seedBase();
        $this->artisan('inventory:audit-price-integrity --product=2 --variant=20 --severity=Critical')->assertExitCode(0);
        $rows = $this->csv('reports/price-integrity/anomalies.csv');
        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->every(fn ($r) => $r['product_id'] === '2' && $r['variant_id'] === '20' && $r['severity'] === 'Critical'));
    }

    private function seedBase(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Summary zero', 'sku' => 'P1', 'code' => 'P1', 'price' => 0, 'stock' => 0, 'reserved' => 0, 'is_sellable' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Stocked zero', 'sku' => 'P2', 'code' => 'P2', 'price' => 1200, 'stock' => 9, 'reserved' => 0, 'is_sellable' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'No variants', 'sku' => 'P3', 'code' => 'P3', 'price' => 0, 'stock' => 0, 'reserved' => 0, 'is_sellable' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 10, 'product_id' => 1, 'variant_name' => 'Good', 'variant_code' => 'V10', 'sell_price' => 500, 'buy_price' => 300, 'stock' => 0, 'reserved' => 0, 'is_active' => 1, 'sales_enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'product_id' => 2, 'variant_name' => 'Bad', 'variant_code' => 'V20', 'sell_price' => 0, 'buy_price' => 200, 'stock' => 1, 'reserved' => 0, 'is_active' => 1, 'sales_enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'product_id' => 2, 'variant_name' => 'Off', 'variant_code' => 'V21', 'sell_price' => 0, 'buy_price' => 0, 'stock' => 0, 'reserved' => 0, 'is_active' => 0, 'sales_enabled' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('warehouse_stocks')->insert(['product_id' => 2, 'product_variant_id' => 20, 'quantity' => 5]);
        DB::table('invoices')->insert(['id' => 1, 'uuid' => '11111111-1111-1111-1111-111111111111', 'status' => 'shipped', 'total' => 900, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_items')->insert([
            ['invoice_id' => 1, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'price' => 900, 'line_total' => 900, 'created_at' => now()->subDay(), 'updated_at' => now()],
            ['invoice_id' => 1, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'price' => 0, 'line_total' => 0, 'created_at' => now(), 'updated_at' => '2026-01-03 04:05:06'],
        ]);
        DB::table('preinvoice_orders')->insert(['id' => 1, 'uuid' => '22222222-2222-2222-2222-222222222222', 'status' => 'draft', 'customer_name' => 'C', 'customer_mobile' => '1', 'customer_address' => 'A', 'province_id' => 1, 'total_price' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('preinvoice_order_items')->insert(['preinvoice_order_id' => 1, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'price' => 0, 'created_at' => now(), 'updated_at' => '2026-01-02 03:04:05']);
    }

    private function schema(): void
    {
        Schema::create('categories', fn (Blueprint $t) => $t->id());
        Schema::create('products', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->string('sku')->nullable(); $t->string('code')->nullable(); $t->unsignedBigInteger('category_id')->nullable(); $t->bigInteger('price')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->boolean('is_sellable')->default(true); $t->timestamp('synced_at')->nullable(); $t->timestamps(); });
        Schema::create('product_variants', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('product_id'); $t->string('variant_name')->nullable(); $t->string('variant_code')->nullable(); $t->bigInteger('sell_price')->nullable(); $t->bigInteger('buy_price')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->boolean('is_active')->default(true); $t->boolean('sales_enabled')->default(true); $t->timestamp('synced_at')->nullable(); $t->timestamps(); });
        Schema::create('warehouse_stocks', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->integer('quantity')->default(0); });
        Schema::create('purchase_items', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('purchase_id')->nullable(); $t->unsignedBigInteger('product_id')->nullable(); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->string('product_name')->nullable(); $t->string('product_code')->nullable(); $t->integer('quantity'); $t->bigInteger('buy_price'); $t->bigInteger('sell_price'); $t->bigInteger('line_total')->default(0); $t->timestamps(); });
        Schema::create('invoices', function (Blueprint $t): void { $t->id(); $t->uuid('uuid')->nullable(); $t->string('status')->nullable(); $t->bigInteger('total')->default(0); $t->timestamps(); });
        Schema::create('invoice_items', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variant_id')->nullable(); $t->integer('quantity'); $t->bigInteger('price'); $t->bigInteger('line_total')->default(0); $t->timestamps(); });
        Schema::create('preinvoice_orders', function (Blueprint $t): void { $t->id(); $t->uuid('uuid')->nullable(); $t->string('status')->nullable(); $t->string('customer_name')->nullable(); $t->string('customer_mobile')->nullable(); $t->text('customer_address')->nullable(); $t->unsignedInteger('province_id')->nullable(); $t->bigInteger('total_price')->default(0); $t->timestamps(); });
        Schema::create('preinvoice_order_items', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('preinvoice_order_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variant_id')->nullable(); $t->integer('quantity'); $t->bigInteger('price'); $t->timestamps(); });
    }

    private function guardReflection(): array
    {
        $command = app(\App\Console\Commands\AuditProductPriceIntegrity::class);
        $install = new \ReflectionMethod($command, 'installWriteQueryGuard');
        $install->setAccessible(true);
        $disable = new \ReflectionMethod($command, 'disableWriteQueryGuard');
        $disable->setAccessible(true);

        return [$install, $disable, $command];
    }

    private function csv(string $path): array
    {
        $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk('local')->get($path))));
        $head = array_shift($lines);
        return array_map(fn ($r) => array_combine($head, $r), array_filter($lines));
    }

    private function where(array $rows, string $key, string $value): array
    { return array_values(array_filter($rows, fn ($r) => $r[$key] === $value)); }

    private function json(string $path): array
    { return json_decode(Storage::disk('local')->get($path), true); }

    private function summary(): array
    { return json_decode(Storage::disk('local')->get('reports/price-integrity/summary.json'), true); }
}
