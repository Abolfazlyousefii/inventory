<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityLogSchemaRecoveryTest extends TestCase
{
    public function test_missing_audit_table_is_created_and_replay_preserves_history(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        $migration = require database_path('migrations/2026_11_14_170000_ensure_activity_logs_table_exists.php');
        $migration->up();
        DB::table('activity_logs')->insert([
            'id' => 19, 'action' => 'financial_account_document_created',
            'subject_type' => 'App\\Models\\Customer', 'subject_id' => 3262,
            'description' => 'Preserved audit document', 'properties' => '{"amount":1234}',
            'occurred_at' => '2026-11-14 12:00:00',
        ]);
        $before = DB::table('activity_logs')->get()->toJson();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('activity_logs')->get()->toJson());
        $this->assertTrue(Schema::hasColumn('activity_logs', 'user_id'));
    }

    public function test_partial_existing_schema_is_not_overwritten(): void
    {
        Schema::create('activity_logs', fn (Blueprint $table) => $table->id());
        DB::table('activity_logs')->insert(['id' => 71]);
        $migration = require database_path('migrations/2026_11_14_170000_ensure_activity_logs_table_exists.php');
        try {
            $migration->up();
            $this->fail('Partial schema was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('review', $e->getMessage());
        }
        $this->assertSame(['id'], Schema::getColumnListing('activity_logs'));
        $this->assertSame(71, (int) DB::table('activity_logs')->value('id'));
    }
}
