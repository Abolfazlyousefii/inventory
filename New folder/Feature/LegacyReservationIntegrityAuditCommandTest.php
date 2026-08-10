<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyReservationIntegrityAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
        Storage::fake('local');
    }

    public function test_command_is_read_only_and_writes_no_mutation_queries(): void
    {
        $this->seedScenario();
        $before = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });

        $this->artisan('inventory:audit-legacy-reservation-integrity')->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
        $this->assertFalse(collect($queries)->contains(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke)\b/i', $sql)));
        $this->assertFalse($this->summary()['data_changed']);
    }

    public function test_classifications_expected_reserved_and_duplicate_rules(): void
    {
        $this->seedScenario();

        $this->artisan('inventory:audit-legacy-reservation-integrity')->assertExitCode(0);

        $legacy = collect($this->csv('reports/legacy-reservation-integrity/legacy-reservation-rows.csv'));
        $variants = collect($this->csv('reports/legacy-reservation-integrity/variant-reconciliation.csv'));
        $missing = collect($this->csv('reports/legacy-reservation-integrity/protected-demand-missing-reservation.csv'));
        $actions = collect($this->csv('reports/legacy-reservation-integrity/proposed-actions.csv'));

        $this->assertSame('L07_UNLINKED_RECENT', $legacy->firstWhere('reservation_id', '1')['classification_code']);
        $this->assertSame('PROTECT_ACTIVE', $legacy->firstWhere('reservation_id', '1')['recommended_action']);
        $this->assertSame('L04_DUPLICATE_LEGACY_AND_OFFICIAL', $legacy->firstWhere('reservation_id', '2')['classification_code']);
        $this->assertSame('L05_INVOICED_OR_CONVERTED', $legacy->firstWhere('reservation_id', '5')['classification_code']);
        $this->assertSame('L06_CANCELLED_EXPIRED_OR_RELEASED', $legacy->firstWhere('reservation_id', '6')['classification_code']);
        $this->assertSame('L06_CANCELLED_EXPIRED_OR_RELEASED', $legacy->firstWhere('reservation_id', '7')['classification_code']);
        $this->assertSame('L01_ACTIVE_DOCUMENT_EXACT', $legacy->firstWhere('reservation_id', '8')['classification_code']);

        $v10 = $variants->firstWhere('variant_id', '10');
        $this->assertSame('7', $v10['protected_document_demand']);
        $this->assertSame('3', $v10['active_temporary_quantity']);
        $this->assertSame('3', $v10['recognized_official_quantity']);
        $this->assertSame('10', $v10['expected_reserved']);
        $this->assertSame('0', $v10['reservation_cache_difference']);
        $this->assertSame('L14_CACHE_MATCHED', $v10['classification_code']);

        $this->assertSame('L12_CACHE_OVER_RESERVED', $variants->firstWhere('variant_id', '20')['classification_code']);
        $this->assertSame('L13_CACHE_UNDER_RESERVED', $variants->firstWhere('variant_id', '30')['classification_code']);
        $missingVariant20 = $missing->first(fn ($row) => $row['preinvoice_order_id'] === '301' && $row['variant_id'] === '20');
        $this->assertNotNull($missingVariant20);
        $this->assertSame('1', $missingVariant20['missing_quantity']);
        $this->assertSame('L11_PROTECTED_DEMAND_WITHOUT_RESERVATION_ROW', $missingVariant20['classification_code']);
        $this->assertNull($actions->first(fn ($row) => $row['action_type'] === 'CANDIDATE_CACHE_DECREASE' && $row['preinvoice_order_id'] === '301'));
    }

    public function test_filters_limit_reports_without_data_changes_or_personal_data(): void
    {
        $this->seedScenario();
        $before = $this->snapshot();

        $this->artisan('inventory:audit-legacy-reservation-integrity --order=301 --variant=10')->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
        $contents = Storage::disk('local')->get('reports/legacy-reservation-integrity/legacy-reservation-rows.csv');
        $this->assertStringNotContainsString('customer', strtolower($contents));
        $this->assertStringNotContainsString('mobile', strtolower($contents));
        $this->assertTrue(collect($this->csv('reports/legacy-reservation-integrity/variant-reconciliation.csv'))->every(fn ($row) => $row['variant_id'] === '10'));
    }

    private function seedScenario(): void
    {
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'Central', 'type' => 'central']);
        DB::table('products')->insert([['id' => 1, 'name' => 'Product'], ['id' => 2, 'name' => 'Over'], ['id' => 3, 'name' => 'Under']]);
        DB::table('product_variants')->insert([
            ['id' => 10, 'product_id' => 1, 'variant_name' => 'Main', 'variant_code' => 'V10', 'stock' => 99, 'reserved' => 10],
            ['id' => 20, 'product_id' => 2, 'variant_name' => 'Over', 'variant_code' => 'V20', 'stock' => 99, 'reserved' => 5],
            ['id' => 30, 'product_id' => 3, 'variant_name' => 'Under', 'variant_code' => 'V30', 'stock' => 99, 'reserved' => 1],
        ]);
        DB::table('warehouse_stocks')->insert([['warehouse_id' => 1, 'product_id' => 1, 'product_variant_id' => 10, 'quantity' => 70], ['warehouse_id' => 1, 'product_id' => 2, 'product_variant_id' => 20, 'quantity' => 70], ['warehouse_id' => 1, 'product_id' => 3, 'product_variant_id' => 30, 'quantity' => 70]]);
        DB::table('preinvoice_orders')->insert([
            ['id' => 301, 'uuid' => 'ord-301', 'status' => 'pending_finance', 'stock_released_at' => null],
            ['id' => 302, 'uuid' => 'ord-302', 'status' => 'converted_to_invoice', 'stock_released_at' => null],
            ['id' => 303, 'uuid' => 'ord-303', 'status' => 'cancelled', 'stock_released_at' => null],
            ['id' => 304, 'uuid' => 'ord-304', 'status' => 'reservation_expired', 'stock_released_at' => null],
            ['id' => 305, 'uuid' => 'ord-305', 'status' => 'warehouse_reviewing', 'stock_released_at' => null],
        ]);
        DB::table('invoices')->insert(['id' => 900, 'preinvoice_order_id' => 302]);
        DB::table('preinvoice_order_items')->insert([
            ['preinvoice_order_id' => 301, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 4],
            ['preinvoice_order_id' => 305, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 3],
            ['preinvoice_order_id' => 301, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1],
            ['preinvoice_order_id' => 301, 'product_id' => 3, 'variant_id' => 30, 'quantity' => 3],
        ]);
        DB::table('preinvoice_draft_reservations')->insert([
            ['id' => 1, 'token' => 'abcdef123456', 'user_id' => 9, 'preinvoice_order_id' => null, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 3, 'reservation_scope' => null, 'expires_at' => now()->addMinutes(5), 'last_seen_at' => null, 'converted_at' => null, 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 2, 'token' => 'dup', 'user_id' => null, 'preinvoice_order_id' => 301, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 1, 'reservation_scope' => '', 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 3, 'token' => 'official', 'user_id' => null, 'preinvoice_order_id' => 301, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 3, 'reservation_scope' => 'official', 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 9, 'token' => 'temp', 'user_id' => 10, 'preinvoice_order_id' => null, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 3, 'reservation_scope' => 'temporary_online', 'expires_at' => now()->addMinutes(10), 'last_seen_at' => now(), 'converted_at' => null, 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 5, 'token' => 'inv', 'user_id' => null, 'preinvoice_order_id' => 302, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 1, 'reservation_scope' => null, 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 6, 'token' => 'can', 'user_id' => null, 'preinvoice_order_id' => 303, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 1, 'reservation_scope' => null, 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 7, 'token' => 'exp', 'user_id' => null, 'preinvoice_order_id' => 304, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 1, 'reservation_scope' => 'unknown', 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
            ['id' => 8, 'token' => 'exact', 'user_id' => null, 'preinvoice_order_id' => 305, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 3, 'reservation_scope' => null, 'expires_at' => null, 'last_seen_at' => null, 'converted_at' => now(), 'released_at' => null, 'release_reason' => null, 'created_at' => now()],
        ]);
    }

    private function schema(): void
    {
        Schema::create('warehouses', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('type')]);
        Schema::create('products', fn (Blueprint $t) => [$t->id(), $t->string('name')]);
        Schema::create('product_variants', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('variant_name')->nullable(), $t->string('variant_code')->nullable(), $t->integer('stock')->default(0), $t->integer('reserved')->default(0)]);
        Schema::create('warehouse_stocks', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('warehouse_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('product_variant_id')->nullable(), $t->integer('quantity')->default(0)]);
        Schema::create('preinvoice_orders', fn (Blueprint $t) => [$t->id(), $t->string('uuid')->nullable(), $t->string('status')->nullable(), $t->timestamp('stock_released_at')->nullable()]);
        Schema::create('invoices', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('preinvoice_order_id')->nullable()]);
        Schema::create('preinvoice_order_items', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('preinvoice_order_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variant_id')->nullable(), $t->integer('quantity')->default(0)]);
        Schema::create('preinvoice_draft_reservations', fn (Blueprint $t) => [$t->id(), $t->string('token')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('preinvoice_order_id')->nullable(), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variant_id')->nullable(), $t->integer('quantity')->default(0), $t->string('reservation_scope')->nullable(), $t->timestamp('expires_at')->nullable(), $t->timestamp('last_seen_at')->nullable(), $t->timestamp('converted_at')->nullable(), $t->timestamp('released_at')->nullable(), $t->string('release_reason')->nullable(), $t->timestamp('created_at')->nullable()]);
    }

    private function csv(string $path): array { $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk('local')->get($path)))); $head = array_shift($lines); return array_map(fn ($row) => array_combine($head, $row), array_filter($lines)); }
    private function summary(): array { return json_decode(Storage::disk('local')->get('reports/legacy-reservation-integrity/summary.json'), true); }
    private function snapshot(): array { return ['variants' => DB::table('product_variants')->orderBy('id')->get()->toArray(), 'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toArray(), 'orders' => DB::table('preinvoice_orders')->orderBy('id')->get()->toArray()]; }
}
