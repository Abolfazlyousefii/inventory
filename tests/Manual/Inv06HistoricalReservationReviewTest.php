<?php

namespace Tests\Manual;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Invoke explicitly with tests/run.php. Creates only an isolated in-memory SQLite DB. */
class Inv06HistoricalReservationReviewTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = (string) config('database.default');
        config()->set('database.connections.inv06_fixture', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('database.default', 'inv06_fixture');
        DB::purge('inv06_fixture');
        $schema = Schema::connection('inv06_fixture');
        $schema->create('preinvoice_draft_reservations', function (Blueprint $t): void {
            $t->increments('id'); $t->string('token')->nullable(); $t->integer('user_id')->nullable();
            $t->integer('preinvoice_order_id')->nullable(); $t->integer('product_id'); $t->integer('variant_id');
            $t->integer('quantity'); $t->string('reservation_scope')->nullable(); $t->string('reservation_tier')->nullable();
            $t->timestamp('last_seen_at')->nullable(); $t->timestamp('expires_at')->nullable();
            $t->timestamp('converted_at')->nullable(); $t->timestamp('released_at')->nullable();
            $t->integer('released_by')->nullable(); $t->string('release_reason')->nullable(); $t->text('release_note')->nullable();
            $t->timestamps();
        });
        $schema->create('preinvoice_orders', function (Blueprint $t): void {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('status');
            $t->string('draft_token')->nullable(); $t->timestamp('stock_released_at')->nullable(); $t->timestamps();
        });
        $schema->create('invoices', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('preinvoice_order_id'); $t->string('uuid'); $t->string('status');
        });
        $schema->create('preinvoice_order_items', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('preinvoice_order_id'); $t->integer('product_id');
            $t->integer('variant_id'); $t->integer('quantity');
        });
        $schema->create('invoice_items', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('invoice_id'); $t->integer('product_id');
            $t->integer('variant_id'); $t->integer('quantity');
        });
        $schema->create('product_variants', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('product_id'); $t->integer('reserved'); $t->integer('stock');
        });
        $schema->create('warehouses', function (Blueprint $t): void {
            $t->increments('id'); $t->string('type');
        });
        $schema->create('warehouse_stocks', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('warehouse_id'); $t->integer('product_variant_id'); $t->integer('quantity');
        });
        $schema->create('activity_logs', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('user_id')->nullable(); $t->string('action');
            $t->string('subject_type'); $t->integer('subject_id'); $t->timestamp('occurred_at')->nullable();
        });
        $schema->create('stock_movements', function (Blueprint $t): void {
            $t->increments('id'); $t->integer('product_id'); $t->integer('product_variant_id')->nullable(); $t->string('reference_type')->nullable(); $t->integer('reference_id')->nullable();
            $t->string('type'); $t->string('reason'); $t->integer('quantity'); $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('reports/inv06-fixture-only');
        config()->set('database.default', $this->previousConnection);
        DB::disconnect('inv06_fixture');
        DB::purge('inv06_fixture');
        parent::tearDown();
    }

    public function test_historical_candidates_are_reported_with_evidence_and_no_database_writes(): void
    {
        $old = now()->subDays(10)->toDateTimeString();
        DB::table('product_variants')->insert(['id' => 7, 'product_id' => 10, 'stock' => 17, 'reserved' => 3]);
        DB::table('warehouses')->insert(['id' => 1, 'type' => 'central']);
        DB::table('warehouse_stocks')->insert(['warehouse_id' => 1, 'product_variant_id' => 7, 'quantity' => 17]);
        DB::table('preinvoice_orders')->insert([
            ['id' => 22, 'uuid' => 'p22', 'status' => 'cancelled', 'stock_released_at' => now()->subDays(2)],
            ['id' => 23, 'uuid' => 'p23', 'status' => 'reserved_waiting_warehouse', 'stock_released_at' => null],
            ['id' => 24, 'uuid' => 'p24', 'status' => 'converted_to_invoice', 'stock_released_at' => null],
        ]);
        DB::table('preinvoice_order_items')->insert(['preinvoice_order_id' => 22, 'product_id' => 10, 'variant_id' => 7, 'quantity' => 60]);
        DB::table('invoices')->insert(['id' => 91, 'preinvoice_order_id' => 24, 'uuid' => 'inv91', 'status' => 'ready_to_ship']);
        $rows = [
            ['id' => 1, 'preinvoice_order_id' => null, 'quantity' => 100],
            ['id' => 2, 'preinvoice_order_id' => 999, 'quantity' => 50],
            ['id' => 3, 'preinvoice_order_id' => 22, 'quantity' => 60],
            ['id' => 4, 'preinvoice_order_id' => null, 'quantity' => 20],
            ['id' => 5, 'preinvoice_order_id' => 23, 'quantity' => 12],
            ['id' => 6, 'preinvoice_order_id' => 24, 'quantity' => 5],
        ];
        foreach ($rows as $row) {
            DB::table('preinvoice_draft_reservations')->insert($row + [
                'product_id' => 10, 'variant_id' => 7, 'token' => 'fixture-'.$row['id'],
                'reservation_scope' => $row['preinvoice_order_id'] ? 'official' : 'temporary_online',
                'created_at' => $old, 'updated_at' => $old, 'last_seen_at' => $old,
            ]);
        }
        DB::table('activity_logs')->insert([
            'subject_type' => \App\Models\PreinvoiceDraftReservation::class, 'subject_id' => 3,
            'action' => 'reservation_manual_release', 'user_id' => 8, 'occurred_at' => $old,
        ]);
        DB::table('stock_movements')->insert([
            'product_id' => 10, 'product_variant_id' => 7,
            'reference_type' => \App\Models\PreinvoiceOrder::class, 'reference_id' => 22,
            'type' => 'out', 'reason' => 'sale', 'quantity' => 60, 'created_at' => $old,
        ]);

        $reservationBefore = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();
        $stockBefore = DB::table('warehouse_stocks')->get()->toJson();
        $movementBefore = DB::table('stock_movements')->get()->toJson();
        $this->assertSame(0, Artisan::call('inventory:review-historical-reservations', [
            '--output' => 'reports/inv06-fixture-only',
        ]));
        $report = json_decode(Storage::disk('local')->get('reports/inv06-fixture-only/review.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(4, $report['summary']['historical_ambiguous_count']);
        $this->assertSame(230, $report['summary']['historical_ambiguous_quantity']);
        $this->assertTrue($report['summary']['matches_reference_baseline']);
        $this->assertSame([1, 2, 3, 4], array_column($report['reservations'], 'reservation_id'));
        $this->assertSame('OPEN_MANUAL_REVIEW', $report['reservations'][2]['review']['status']);
        $this->assertSame(60, $report['reservations'][2]['order']['matching_line_quantity']);
        $this->assertSame(1, $report['reservations'][2]['activity_log_total']);
        $this->assertSame(1, $report['reservations'][2]['stock_movements_with_explicit_order_reference_total']);
        $this->assertSame($reservationBefore, DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson());
        $this->assertSame($stockBefore, DB::table('warehouse_stocks')->get()->toJson());
        $this->assertSame($movementBefore, DB::table('stock_movements')->get()->toJson());
    }

    public function test_explicit_ids_show_other_classifications_and_missing_ids_without_releasing(): void
    {
        DB::table('preinvoice_draft_reservations')->insert([
            'id' => 9, 'product_id' => 10, 'variant_id' => 7, 'quantity' => 3,
            'released_at' => now()->subDay(), 'release_reason' => 'consumed',
            'created_at' => now()->subDays(12), 'updated_at' => now()->subDay(),
        ]);
        $this->assertSame(0, Artisan::call('inventory:review-historical-reservations', [
            '--ids' => '9,10', '--output' => 'reports/inv06-fixture-only',
        ]));
        $report = json_decode(Storage::disk('local')->get('reports/inv06-fixture-only/review.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([10], $report['summary']['not_found_ids']);
        $this->assertSame('released', $report['reservations'][0]['classification']);
        $this->assertSame('OPEN_MANUAL_REVIEW', $report['reservations'][0]['review']['status']);
        $this->assertSame(0, $report['summary']['reservations_released']);
        $this->assertNotNull(DB::table('preinvoice_draft_reservations')->where('id', 9)->value('released_at'));
    }
}
