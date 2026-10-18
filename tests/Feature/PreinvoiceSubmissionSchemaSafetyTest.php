<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreinvoiceSubmissionSchemaSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_has_submission_identity_and_repeated_migration_preserves_it(): void
    {
        $this->assertTrue(Schema::hasColumn('preinvoice_orders', 'submission_token'));
        $order = \App\Models\PreinvoiceOrder::create([
            'uuid' => '09421', 'status' => 'draft', 'customer_name' => 'Schema test', 'customer_mobile' => '09120000000',
            'submission_token' => '31a73cd1-1b23-4a0c-9979-c050a66d64c4',
        ]);
        $before = DB::table('preinvoice_orders')->where('id', $order->id)->first();
        $migration = require database_path('migrations/2026_10_19_000001_add_preinvoice_submission_token.php');
        $migration->up();
        $migration->down();
        $this->assertEquals($before, DB::table('preinvoice_orders')->where('id', $order->id)->first());
    }
}
