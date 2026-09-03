<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockReservationIntegrityAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
        Storage::fake('local');
    }

    public function test_stock_is_compared_only_against_central_warehouse_and_non_central_stock_does_not_create_s01(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $s01Rows = $this->csv('reports/stock-reservation-integrity/central-stock-cache-desync.csv');
        $this->assertCount(1, $s01Rows);
        $this->assertSame('20', $s01Rows[0]['variant_id']);
        $this->assertSame('S01', $s01Rows[0]['anomaly_code']);
        $this->assertSame('4', $s01Rows[0]['central_available_stock']);
        $this->assertSame('99', $s01Rows[0]['non_central_stock']);

        $summary = $this->summary();
        $this->assertSame(1, $summary['central_stock_cache_desync']);
        $this->assertFalse($summary['data_changed']);
    }

    public function test_reserved_cache_is_compared_only_with_active_reservations_and_released_or_consumed_are_ignored(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $rows = collect($this->csv('reports/stock-reservation-integrity/reservation-cache-desync.csv'));
        $r01 = $rows->firstWhere('anomaly_code', 'R01');

        $this->assertNotNull($r01);
        $this->assertSame('20', $r01['variant_id']);
        $this->assertSame('1', $r01['cached_reserved']);
        $this->assertSame('4', $r01['active_reserved_quantity']);
        $this->assertSame('1', $r01['temporary_online_reserved']);
        $this->assertSame('1', $r01['temporary_in_person_reserved']);
        $this->assertSame('2', $r01['official_reserved']);
        $this->assertStringNotContainsString('104', $r01['reservation_ids']);
        $this->assertStringNotContainsString('105', $r01['reservation_ids']);
    }

    public function test_reserved_greater_than_stock_alone_is_not_an_anomaly_when_cache_matches_active_reserved(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $rows = collect($this->csv('reports/stock-reservation-integrity/reservation-cache-desync.csv'));
        $this->assertNull($rows->first(fn ($row) => $row['variant_id'] === '40' && in_array($row['anomaly_code'], ['S01', 'R01'], true)));
    }

    public function test_stale_temporary_and_invalid_official_reservations_are_reported_without_changes(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $staleRows = collect($this->csv('reports/stock-reservation-integrity/stale-temporary-reservations.csv'));
        $officialRows = collect($this->csv('reports/stock-reservation-integrity/invalid-official-reservations.csv'));

        $this->assertEqualsCanonicalizing(['R03', 'R04'], $staleRows->pluck('anomaly_code')->all());
        $this->assertSame('R05', $officialRows->first()['anomaly_code']);
        $this->assertNull(DB::table('preinvoice_draft_reservations')->where('id', 101)->value('released_at'));
        $this->assertNull(DB::table('preinvoice_draft_reservations')->where('id', 103)->value('released_at'));
    }

    public function test_zero_price_reports_distinguish_central_non_central_and_active_reservations(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $central = collect($this->csv('reports/stock-reservation-integrity/central-stock-zero-prices.csv'));
        $nonCentral = collect($this->csv('reports/stock-reservation-integrity/non-central-stock-zero-prices.csv'));
        $reservation = collect($this->csv('reports/stock-reservation-integrity/reservation-cache-desync.csv'));

        $this->assertSame('P01', $central->firstWhere('variant_id', '20')['anomaly_code']);
        $this->assertNull($central->firstWhere('variant_id', '30'));
        $this->assertSame('P02', $nonCentral->firstWhere('variant_id', '30')['anomaly_code']);
        $this->assertSame('P03', $reservation->first(fn ($row) => $row['variant_id'] === '20' && $row['anomaly_code'] === 'P03')['anomaly_code']);
    }

    public function test_no_write_queries_are_executed_by_command(): void
    {
        $this->seedBase();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('inventory:audit-stock-reservation-integrity')->assertExitCode(0);

        $this->assertFalse(collect($queries)->contains(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke)\b/i', $sql)));
    }

    public function test_json_output_and_filters_work(): void
    {
        $this->seedBase();

        $this->artisan('inventory:audit-stock-reservation-integrity --format=json --product=2 --variant=20')->assertExitCode(0);

        Storage::disk('local')->assertExists('reports/stock-reservation-integrity/summary.json');
        Storage::disk('local')->assertExists('reports/stock-reservation-integrity/central-stock-cache-desync.json');
        $rows = $this->readJsonReport('reports/stock-reservation-integrity/central-stock-cache-desync.json');
        $this->assertTrue(collect($rows)->every(fn ($row) => $row['product_id'] === 2 && $row['variant_id'] === 20));
    }

    private function seedBase(): void
    {
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Central', 'type' => 'central'],
            ['id' => 2, 'name' => 'Branch', 'type' => 'branch'],
        ]);
        DB::table('products')->insert([
            ['id' => 2, 'name' => 'Central zero'],
            ['id' => 3, 'name' => 'Branch zero'],
            ['id' => 4, 'name' => 'Reserved greater than stock'],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 20, 'product_id' => 2, 'variant_name' => 'Central bad', 'variant_code' => 'V20', 'sell_price' => 0, 'stock' => 5, 'reserved' => 1, 'is_active' => 1, 'sales_enabled' => 1],
            ['id' => 30, 'product_id' => 3, 'variant_name' => 'Branch only', 'variant_code' => 'V30', 'sell_price' => 0, 'stock' => 0, 'reserved' => 0, 'is_active' => 1, 'sales_enabled' => 1],
            ['id' => 40, 'product_id' => 4, 'variant_name' => 'Oversold reserved', 'variant_code' => 'V40', 'sell_price' => 100, 'stock' => 0, 'reserved' => 5, 'is_active' => 1, 'sales_enabled' => 1],
        ]);
        DB::table('warehouse_stocks')->insert([
            ['warehouse_id' => 1, 'product_id' => 2, 'product_variant_id' => 20, 'quantity' => 4],
            ['warehouse_id' => 2, 'product_id' => 2, 'product_variant_id' => 20, 'quantity' => 99],
            ['warehouse_id' => 2, 'product_id' => 3, 'product_variant_id' => 30, 'quantity' => 7],
        ]);
        DB::table('preinvoice_orders')->insert([
            ['id' => 501, 'status' => 'reserved_waiting_warehouse', 'stock_released_at' => null],
            ['id' => 502, 'status' => 'converted_to_invoice', 'stock_released_at' => null],
        ]);
        DB::table('preinvoice_draft_reservations')->insert([
            ['id' => 101, 'preinvoice_order_id' => null, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'reservation_scope' => 'temporary_online', 'expires_at' => now()->subMinute(), 'last_seen_at' => now(), 'converted_at' => null, 'released_at' => null, 'release_reason' => null],
            ['id' => 102, 'preinvoice_order_id' => null, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'reservation_scope' => 'temporary_in_person', 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => null, 'released_at' => null, 'release_reason' => null],
            ['id' => 103, 'preinvoice_order_id' => 501, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 2, 'reservation_scope' => 'official', 'expires_at' => null, 'last_seen_at' => now(), 'converted_at' => null, 'released_at' => null, 'release_reason' => null],
            ['id' => 104, 'preinvoice_order_id' => null, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 8, 'reservation_scope' => 'temporary_online', 'expires_at' => now()->subHour(), 'last_seen_at' => now(), 'converted_at' => null, 'released_at' => now(), 'release_reason' => 'manual_release'],
            ['id' => 105, 'preinvoice_order_id' => 501, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 8, 'reservation_scope' => 'official', 'expires_at' => null, 'last_seen_at' => now(), 'converted_at' => now(), 'released_at' => null, 'release_reason' => 'consumed'],
            ['id' => 106, 'preinvoice_order_id' => 502, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'reservation_scope' => 'official', 'expires_at' => null, 'last_seen_at' => now(), 'converted_at' => now(), 'released_at' => null, 'release_reason' => null],
            ['id' => 107, 'preinvoice_order_id' => null, 'product_id' => 4, 'variant_id' => 40, 'quantity' => 5, 'reservation_scope' => 'temporary_online', 'expires_at' => null, 'last_seen_at' => now(), 'converted_at' => null, 'released_at' => null, 'release_reason' => null],
        ]);
    }

    private function schema(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant_name')->nullable();
            $table->string('variant_code')->nullable();
            $table->bigInteger('sell_price')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('reserved')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('sales_enabled')->default(true);
        });
        Schema::create('warehouse_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('quantity')->default(0);
        });
        Schema::create('preinvoice_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamp('stock_released_at')->nullable();
        });
        Schema::create('preinvoice_draft_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('preinvoice_order_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->string('reservation_scope')->nullable();
        });
    }

    private function csv(string $path): array
    {
        $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk('local')->get($path))));
        $head = array_shift($lines);

        return array_map(fn ($row) => array_combine($head, $row), array_filter($lines));
    }

    private function readJsonReport(string $path): array
    {
        return json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function summary(): array
    {
        return $this->readJsonReport('reports/stock-reservation-integrity/summary.json');
    }
}
