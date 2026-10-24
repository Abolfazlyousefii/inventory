<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class LegacyBundleSchemaUpgradeTest extends TestCase
{
    public function test_upgrade_preserves_legacy_prices_components_and_stock_and_can_run_twice(): void
    {
        Schema::create('products',function(Blueprint $t){$t->id();$t->integer('price');});
        Schema::create('product_bundle_components',function(Blueprint $t){$t->id();$t->integer('bundle_variant_id');$t->integer('component_variant_id');$t->integer('quantity');});
        Schema::create('warehouse_stocks',function(Blueprint $t){$t->id();$t->integer('quantity');});
        DB::table('products')->insert(['id'=>1,'price'=>285600000]);
        DB::table('product_bundle_components')->insert(['id'=>1,'bundle_variant_id'=>1,'component_variant_id'=>9,'quantity'=>3]);
        DB::table('warehouse_stocks')->insert(['id'=>1,'quantity'=>27]);
        $migration=require database_path('migrations/2026_10_24_210000_complete_legacy_bundle_settings.php');
        $migration->up();$migration->up();
        $this->assertSame(285600000,(int)DB::table('products')->value('price'));
        $this->assertNull(DB::table('products')->value('bundle_site_auto_publish'));
        $this->assertNull(DB::table('products')->value('bundle_quantity_mode'));
        $part=DB::table('product_bundle_components')->first();
        $this->assertSame(3,(int)$part->quantity);
        $this->assertSame('percent',$part->discount_type);
        $this->assertSame(0,(int)$part->discount_value);
        $this->assertSame(27,(int)DB::table('warehouse_stocks')->value('quantity'));
        $this->assertTrue(Schema::hasTable('bundle_assemblies'));
    }
}
