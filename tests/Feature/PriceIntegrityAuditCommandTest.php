<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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

    public function test_command_reports_required_price_integrity_scenarios_without_updates(): void
    {
        DB::table('products')->insert([
            ['id'=>1,'name'=>'Stocked zero','code'=>'P1','category_id'=>null,'price'=>0,'stock'=>0,'reserved'=>0,'is_sellable'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>2,'name'=>'Desync','code'=>'P2','category_id'=>null,'price'=>0,'stock'=>0,'reserved'=>0,'is_sellable'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>3,'name'=>'Buy only','code'=>'P3','category_id'=>null,'price'=>0,'stock'=>0,'reserved'=>0,'is_sellable'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('product_variants')->insert([
            ['id'=>10,'product_id'=>1,'variant_name'=>'Red','variant_code'=>'V10','sell_price'=>0,'buy_price'=>100,'stock'=>0,'reserved'=>0,'is_active'=>1,'sales_enabled'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>11,'product_id'=>1,'variant_name'=>'Off','variant_code'=>'V11','sell_price'=>0,'buy_price'=>0,'stock'=>0,'reserved'=>0,'is_active'=>0,'sales_enabled'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>20,'product_id'=>2,'variant_name'=>'Good','variant_code'=>'V20','sell_price'=>500,'buy_price'=>300,'stock'=>0,'reserved'=>0,'is_active'=>1,'sales_enabled'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>30,'product_id'=>3,'variant_name'=>'No margin','variant_code'=>'V30','sell_price'=>0,'buy_price'=>200,'stock'=>0,'reserved'=>0,'is_active'=>1,'sales_enabled'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('warehouse_stocks')->insert([
            ['product_id'=>1,'product_variant_id'=>10,'quantity'=>5], ['product_id'=>3,'product_variant_id'=>30,'quantity'=>3]
        ]);
        DB::table('purchase_items')->insert([['product_id'=>3,'product_variant_id'=>30,'quantity'=>7,'buy_price'=>200,'sell_price'=>0,'created_at'=>now()]]);
        DB::table('invoices')->insert([['id'=>1,'status'=>'final','total'=>1000,'created_at'=>now(),'updated_at'=>now()]]);
        DB::table('invoice_items')->insert([
            ['invoice_id'=>1,'product_id'=>1,'variant_id'=>10,'quantity'=>1,'price'=>900,'line_total'=>900,'created_at'=>now()->subDay(),'updated_at'=>now()],
            ['invoice_id'=>1,'product_id'=>1,'variant_id'=>10,'quantity'=>1,'price'=>0,'line_total'=>0,'created_at'=>now(),'updated_at'=>now()],
        ]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $this->artisan('inventory:audit-price-integrity --format=csv')->assertExitCode(0);

        $this->assertFalse(collect($queries)->contains(fn($sql) => preg_match('/\bupdate\b/i', $sql)));
        $files = Storage::disk('local')->allFiles('reports/price-integrity');
        $this->assertNotEmpty(preg_grep('/anomalies.*\.csv$/', $files));
        $this->assertNotEmpty(preg_grep('/summary.*\.json$/', $files));
        $summary = json_decode(Storage::disk('local')->get(collect($files)->first(fn($f) => str_contains($f, 'summary'))), true);
        $this->assertGreaterThanOrEqual(1, $summary['invoice_zero_line_items']);
        $this->assertGreaterThanOrEqual(1, $summary['high_confidence_suggestions']);
        $this->assertGreaterThanOrEqual(1, $summary['manual_pricing_required']);
        $this->assertFalse($summary['data_changed']);
    }

    private function schema(): void
    {
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('code')->nullable(); $t->unsignedBigInteger('category_id')->nullable(); $t->bigInteger('price')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->boolean('is_sellable')->default(true); $t->timestamp('synced_at')->nullable(); $t->timestamps(); });
        Schema::create('product_variants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->string('variant_name')->nullable(); $t->string('variant_code')->nullable(); $t->bigInteger('sell_price')->nullable(); $t->bigInteger('buy_price')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->boolean('is_active')->default(true); $t->boolean('sales_enabled')->default(true); $t->timestamp('synced_at')->nullable(); $t->timestamps(); });
        Schema::create('warehouse_stocks', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->integer('quantity')->default(0); });
        Schema::create('purchase_items', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->integer('quantity'); $t->bigInteger('buy_price'); $t->bigInteger('sell_price'); $t->timestamp('created_at')->nullable(); });
        Schema::create('invoices', function (Blueprint $t) { $t->id(); $t->string('status')->nullable(); $t->bigInteger('total')->default(0); $t->timestamps(); });
        Schema::create('invoice_items', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variant_id')->nullable(); $t->integer('quantity'); $t->bigInteger('price'); $t->bigInteger('line_total')->default(0); $t->timestamps(); });
    }
}
