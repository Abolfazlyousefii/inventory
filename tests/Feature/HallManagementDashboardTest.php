<?php

namespace Tests\Feature;

use App\Http\Controllers\Tracking\HallTrackingController;
use App\Models\PreinvoiceOrder;
use App\Services\Tracking\HallManagementDashboard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HallManagementDashboardTest extends TestCase
{
    public function test_management_display_never_enables_the_debug_toolbar(): void
    {
        $bar = app('debugbar');
        $bar->enable();
        new HallTrackingController;
        $this->assertFalse($bar->isEnabled());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Tehran')->setDate(2026, 11, 1)->setTime(14, 0));
        config(['app.timezone' => 'Asia/Tehran', 'hall_display.access_key' => 'test-manager-key']);

        foreach (['customers' => ['name', 'company_name', 'first_name', 'last_name'], 'users' => ['name']] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->id();
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
        foreach (['preinvoice_orders', 'invoices'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('uuid');
                $table->string('status');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->string('customer_name')->nullable();
                $table->dateTime('document_date')->nullable();
                $table->dateTime('items_updated_at')->nullable();
                if ($name === 'invoices') {
                    $table->unsignedBigInteger('preinvoice_order_id')->nullable();
                    $table->bigInteger('total')->default(0);
                    $table->dateTime('status_changed_at')->nullable();
                    $table->dateTime('shipped_at')->nullable();
                } else {
                    $table->unsignedBigInteger('created_by')->nullable();
                    $table->boolean('is_auto_draft')->default(false);
                    $table->bigInteger('total_price')->default(0);
                    $table->dateTime('stock_released_at')->nullable();
                    $table->dateTime('stock_frozen_until')->nullable();
                }
                $table->timestamps();
            });
        }
        foreach (['invoice_items' => 'invoice_id', 'preinvoice_order_items' => 'preinvoice_order_id'] as $name => $foreign) {
            Schema::create($name, function (Blueprint $table) use ($foreign) {
                $table->id();
                $table->unsignedBigInteger($foreign);
                $table->integer('quantity')->default(1);
            });
        }
        Schema::create('sales_havaleh_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('field_name')->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->dateTime('done_at');
        });
        Schema::create('warehouse_review_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preinvoice_order_id');
            $table->string('status_from')->nullable();
            $table->string('status_to')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('method');
            $table->bigInteger('amount');
            $table->dateTime('paid_at')->nullable();
        });
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_payment_id');
            $table->date('received_at')->nullable();
        });
        Schema::create('preinvoice_draft_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preinvoice_order_id')->nullable();
            $table->string('reservation_scope')->nullable();
            $table->integer('quantity')->default(1);
            $table->dateTime('converted_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function order(array $overrides = [], bool $reserved = true): int
    {
        $id = DB::table('preinvoice_orders')->insertGetId(array_merge([
            'uuid' => 'P-'.uniqid(), 'status' => 'pending_finance', 'total_price' => 128456789,
            'customer_name' => 'مشتری نمونه', 'created_at' => now()->subHours(2), 'updated_at' => now(),
        ], $overrides));
        if ($reserved && in_array($overrides['status'] ?? 'pending_finance', PreinvoiceOrder::reservationHoldingStatuses(), true)) {
            $this->reservation($id);
        }

        return $id;
    }

    private function reservation(int $id, array $overrides = []): void
    {
        DB::table('preinvoice_draft_reservations')->insert(array_merge([
            'preinvoice_order_id' => $id, 'reservation_scope' => 'official', 'quantity' => 1,
            'created_at' => now()->subMinutes(10), 'updated_at' => now(), 'expires_at' => now()->addHours(2),
        ], $overrides));
    }

    public function test_only_real_open_official_reservations_inside_24_hours_are_visible(): void
    {
        $valid = $this->order();
        $this->reservation($valid); // Many reserved lines still produce one card.
        $this->order([], false);
        foreach ([
            ['reservation_scope' => 'temporary_online'], ['released_at' => now()],
            ['converted_at' => now()], ['release_reason' => 'released'], ['quantity' => 0],
            ['expires_at' => now()], ['created_at' => now()->subHours(24)],
            ['created_at' => now()->addMinute()],
        ] as $invalid) {
            $id = $this->order([], false);
            $this->reservation($id, $invalid);
        }
        $payload = app(HallManagementDashboard::class)->snapshot();
        $this->assertCount(1, $payload['documents']);
        $this->assertSame('order:'.$valid, $payload['documents'][0]['key']);
        $this->assertSame('reserved', $payload['documents'][0]['stage']);
    }

    public function test_drafts_and_returned_sales_are_hidden_even_if_recent_or_reserved(): void
    {
        foreach (['draft', 'returned_to_sales'] as $status) {
            $id = $this->order(['status' => $status]);
            $this->reservation($id);
        }
        $this->invoice(['status' => 'returned_to_sales_after_collection']);
        $this->assertEmpty(app(HallManagementDashboard::class)->snapshot()['documents']);
    }

    public function test_released_order_and_mixed_expired_reservations_are_not_active(): void
    {
        $this->order(['stock_released_at' => now()]);
        $this->order(['stock_frozen_until' => now()->subMinute()]);
        $id = $this->order();
        $this->reservation($id, ['expires_at' => now()->subSecond()]);
        $this->assertEmpty(app(HallManagementDashboard::class)->snapshot()['documents']);
    }

    public function test_window_starts_at_official_reservation_not_draft_creation_or_last_edit(): void
    {
        $fresh = $this->order(['created_at' => now()->subDays(5)]);
        $old = $this->order(['created_at' => now()->subDays(5)], false);
        $this->reservation($old, ['created_at' => now()->subHours(25), 'updated_at' => now()]);
        $docs = app(HallManagementDashboard::class)->snapshot()['documents'];
        $this->assertCount(1, $docs);
        $this->assertSame('order:'.$fresh, $docs[0]['key']);
        $this->assertSame(600, $docs[0]['stage_seconds']);
    }

    private function invoice(array $overrides = []): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'uuid' => 'F-'.uniqid(), 'status' => 'pending_collection', 'total' => 128456789,
            'customer_name' => 'مشتری نمونه', 'created_at' => now()->subHour(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_reapproval_has_an_independent_fifth_queue_without_duplicating_the_invoice(): void
    {
        $invoiceId = $this->invoice(['status' => 'pending_finance_reapproval']);
        $this->order(['status' => 'finance_reviewing']);

        $data = app(HallManagementDashboard::class)->snapshot();
        $this->assertCount(5, $data['stages']);
        $this->assertSame('reapproval', HallManagementDashboard::stage('invoice', 'pending_finance_reapproval'));
        $this->assertSame('finance', HallManagementDashboard::stage('preinvoice', 'finance_reviewing'));
        $this->assertSame(1, collect($data['stages'])->firstWhere('key', 'reapproval')['count']);
        $this->assertSame(1, collect($data['stages'])->firstWhere('key', 'finance')['count']);
        $this->assertCount(1, array_filter($data['documents'], fn ($d) => $d['type'] === 'invoice' && $d['source_id'] === $invoiceId));
    }

    public function test_monthly_issued_invoice_count_uses_tehran_jalali_boundaries_and_excludes_cancelled(): void
    {
        // 1405/08/01 = 2026-10-23; 1405/09/01 = 2026-11-22.
        $this->invoice(['document_date' => '2026-10-22 23:59:59']);
        $this->invoice(['document_date' => '2026-10-23 00:00:00']);
        $this->invoice(['document_date' => '2026-11-05 09:20:00']);
        $this->invoice(['document_date' => '2026-11-22 00:00:00']);
        $this->invoice(['document_date' => '2026-11-04 12:00:00', 'status' => 'not_shipped']);

        $metrics = app(HallManagementDashboard::class)->snapshot()['metrics'];
        $this->assertSame(2, $metrics['month_invoice_count']);
        $this->assertSame('1405/08', $metrics['month_jalali']);
    }

    public function test_monthly_issued_invoices_span_all_31_days_of_farvardin_without_crossing_next_month(): void
    {
        $this->travelTo(now('Asia/Tehran')->setDate(2026, 4, 5)->setTime(14, 0));
        // 1405/01/01 = 2026-03-21 and 1405/02/01 = 2026-04-21.
        $this->invoice(['document_date'=>'2026-03-20 23:59:59']);
        $this->invoice(['document_date'=>'2026-03-21 00:00:00']);
        $this->invoice(['document_date'=>'2026-04-20 23:59:59']);
        $this->invoice(['document_date'=>'2026-04-21 00:00:00']);

        $metrics = app(HallManagementDashboard::class)->snapshot()['metrics'];
        $this->assertSame(2, $metrics['month_invoice_count']);
        $this->assertSame('1405/01', $metrics['month_jalali']);
    }

    public function test_real_statuses_map_to_five_queues(): void
    {
        $map = [
            ['preinvoice', 'draft', 'draft'], ['preinvoice', 'returned_to_sales', 'draft'],
            ['preinvoice', 'pending_finance', 'reserved'], ['preinvoice', 'finance_reviewing', 'finance'],
            ['preinvoice', 'warehouse_approved_waiting_finance', 'finance'],
            ['preinvoice', 'reserved_waiting_warehouse', 'reserved'], ['preinvoice', 'returned_to_warehouse', 'warehouse'],
            ['invoice', 'pending_finance_reapproval', 'reapproval'], ['invoice', 'returned_to_sales_after_collection', 'draft'],
            ['invoice', 'pending_collection', 'warehouse'], ['invoice', 'collecting', 'warehouse'],
            ['invoice', 'checking_discrepancy', 'warehouse'], ['invoice', 'final_check', 'warehouse'],
            ['invoice', 'packing', 'shipping'], ['invoice', 'ready_to_ship', 'shipping'],
            ['invoice', 'shipped', null], ['invoice', 'not_shipped', null],
            ['preinvoice', 'converted_to_invoice', null], ['preinvoice', 'cancelled_by_finance', null],
            ['invoice', 'unexpected', null],
        ];
        foreach ($map as [$type, $status, $stage]) {
            $this->assertSame($stage, HallManagementDashboard::stage($type, $status), $status);
        }
    }

    public function test_feed_keeps_all_active_documents_and_excludes_converted_duplicates(): void
    {
        $parent = $this->order();
        $this->invoice(['preinvoice_order_id' => $parent]);
        for ($i = 0; $i < 130; $i++) {
            $this->invoice();
        }
        $this->invoice(['status' => 'shipped']);
        $this->invoice(['status' => 'not_shipped']);
        $payload = app(HallManagementDashboard::class)->snapshot();
        $this->assertCount(131, $payload['documents']);
        $this->assertSame(131, $payload['summary']['total']);
        $this->assertCount(5, $payload['stages']);
    }

    public function test_amount_is_raw_rial_and_line_count_is_not_sum_of_units(): void
    {
        $id = $this->invoice();
        DB::table('invoice_items')->insert([['invoice_id' => $id, 'quantity' => 50], ['invoice_id' => $id, 'quantity' => 3]]);
        $doc = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->assertSame(128456789, $doc['amount_rial']);
        $this->assertSame(2, $doc['item_count']);
        $this->assertSame(53, $doc['unit_count']);
    }

    public function test_identity_and_total_time_survive_conversion(): void
    {
        $id = $this->order(['created_at' => now()->subHours(3)]);
        $before = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->invoice(['preinvoice_order_id' => $id]);
        $after = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->assertSame($before['key'], $after['key']);
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertSame(10800, $after['total_seconds']);
    }

    public function test_queue_clock_does_not_reset_on_item_edit_or_internal_status_change(): void
    {
        $id = $this->invoice(['status' => 'collecting', 'updated_at' => now(), 'status_changed_at' => now()->subMinutes(5)]);
        DB::table('sales_havaleh_histories')->insert([
            ['invoice_id' => $id, 'field_name' => 'status', 'old_value' => 'pending_finance_reapproval', 'new_value' => 'pending_collection', 'done_at' => now()->subMinutes(40)],
            ['invoice_id' => $id, 'field_name' => 'status', 'old_value' => 'pending_collection', 'new_value' => 'collecting', 'done_at' => now()->subMinutes(5)],
        ]);
        $doc = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->assertSame(2400, $doc['stage_seconds']);
        $this->assertTrue($doc['stage_time_known']);
    }

    public function test_legacy_unknown_queue_time_is_not_fabricated_from_updated_at(): void
    {
        $this->invoice(['updated_at' => now()]);
        $doc = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->assertNull($doc['stage_seconds']);
        $this->assertFalse($doc['stage_time_known']);
    }

    public function test_saved_drafts_and_empty_autosaves_are_not_displayed(): void
    {
        $this->order(['status' => 'draft', 'is_auto_draft' => true]);
        $this->order(['status' => 'draft', 'updated_at' => now()->subDays(3)]);
        $id = $this->order(['status' => 'draft']);
        DB::table('preinvoice_order_items')->insert(['preinvoice_order_id' => $id, 'quantity' => 1]);
        $this->assertEmpty(app(HallManagementDashboard::class)->snapshot()['documents']);
    }

    public function test_today_kpis_use_whole_day_and_deduplicate_cheques_by_payment(): void
    {
        $this->invoice(['total' => 1000, 'document_date' => now()]);
        $this->invoice(['total' => 9000, 'status' => 'not_shipped', 'document_date' => now()]);
        $this->invoice(['total' => 7000, 'document_date' => now()->subDay()]);
        DB::table('invoice_payments')->insert([
            ['id' => 1, 'method' => 'cash', 'amount' => 300, 'paid_at' => now()],
            ['id' => 2, 'method' => 'online', 'amount' => 400, 'paid_at' => now()],
            ['id' => 3, 'method' => 'cheque', 'amount' => 500, 'paid_at' => now()->subDay()],
            ['id' => 4, 'method' => 'cash', 'amount' => 800, 'paid_at' => now()->subDay()],
        ]);
        DB::table('cheques')->insert([['invoice_payment_id' => 3, 'received_at' => now()->toDateString()], ['invoice_payment_id' => 3, 'received_at' => now()->toDateString()]]);
        $kpi = app(HallManagementDashboard::class)->snapshot()['metrics'];
        $this->assertSame(1000, $kpi['sales_rial']);
        $this->assertSame(700, $kpi['cash_rial']);
        $this->assertSame(500, $kpi['cheque_rial']);
        $this->assertSame(1, $kpi['cheque_count']);
    }

    public function test_guest_and_old_customer_display_cookie_cannot_read_management_money(): void
    {
        $this->getJson('/hall/data')->assertForbidden();
        $this->withCredentials()->withCookie('hall_display_access', hash('sha256', 'test-manager-key'))->getJson('/hall/data')->assertForbidden();
    }

    public function test_unlocked_manager_gets_private_new_dashboard_and_real_data(): void
    {
        $this->invoice();
        $proof = hash_hmac('sha256', 'hall-management-v1', 'test-manager-key');
        $this->withCookie('hall_management_access', $proof)->get('/hall')
            ->assertOk()->assertSee('hall-management/dashboard.js')->assertDontSee('tracking/tracking.js');
        $this->withCredentials()->withCookie('hall_management_access', $proof)->getJson('/hall/data')
            ->assertOk()->assertJsonPath('summary.total', 1)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_missing_key_fails_closed_and_unlock_issues_scoped_manager_cookie(): void
    {
        config(['hall_display.access_key' => '']);
        $this->withCredentials()->withCookie('hall_management_access', hash_hmac('sha256', 'hall-management-v1', ''))->getJson('/hall/data')->assertForbidden();
        config(['hall_display.access_key' => 'test-manager-key']);
        $this->post('/hall/unlock', ['access_key' => 'test-manager-key'])
            ->assertRedirect('/hall')->assertCookie('hall_management_access');
    }

    public function test_finance_review_starts_when_leaving_reserved_queue_and_reentry_resets_it(): void
    {
        $id = $this->order(['status' => 'finance_reviewing']);
        DB::table('warehouse_review_logs')->insert([
            ['preinvoice_order_id' => $id, 'status_from' => 'draft', 'status_to' => 'pending_finance', 'created_at' => now()->subMinutes(40)],
            ['preinvoice_order_id' => $id, 'status_from' => 'pending_finance', 'status_to' => 'finance_reviewing', 'created_at' => now()->subMinutes(5)],
        ]);
        $this->assertSame(300, app(HallManagementDashboard::class)->snapshot()['documents'][0]['stage_seconds']);
        DB::table('warehouse_review_logs')->insert([
            ['preinvoice_order_id' => $id, 'status_from' => 'finance_reviewing', 'status_to' => 'returned_to_sales', 'created_at' => now()->subMinutes(3)],
            ['preinvoice_order_id' => $id, 'status_from' => 'returned_to_sales', 'status_to' => 'finance_reviewing', 'created_at' => now()->subMinute()],
        ]);
        $this->assertSame(60, app(HallManagementDashboard::class)->snapshot()['documents'][0]['stage_seconds']);
    }

    public function test_cancelled_preinvoice_is_an_outcome_not_an_active_card(): void
    {
        $this->order(['status' => 'cancelled_by_finance']);
        $payload = app(HallManagementDashboard::class)->snapshot();
        $this->assertEmpty($payload['documents']);
        $this->assertSame('لغوشده توسط مالی', $payload['events'][0]['label']);
    }

    public function test_snapshot_does_not_write_operational_records(): void
    {
        $this->invoice();
        DB::enableQueryLog();
        app(HallManagementDashboard::class)->snapshot();
        foreach (DB::getQueryLog() as $query) {
            $this->assertMatchesRegularExpression('/^select\s/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_new_queue_ledger_provides_entry_time_without_legacy_history(): void
    {
        (require database_path('migrations/2026_11_01_120000_create_hall_queue_transitions_table.php'))->up();
        $id = $this->invoice(['status' => 'collecting']);
        DB::table('hall_queue_transitions')->insert([
            'workflow_key' => 'order:invoice:'.$id, 'source_type' => 'invoice', 'source_id' => $id,
            'document_number' => 'F-123',
            'from_stage' => null, 'to_stage' => 'warehouse', 'status_to' => 'pending_collection',
            'entered_at' => now()->subMinutes(13),
        ]);
        $doc = app(HallManagementDashboard::class)->snapshot()['documents'][0];
        $this->assertTrue($doc['stage_time_known']);
        $this->assertSame(780, $doc['stage_seconds']);
    }

    public function test_timeline_requires_hall_access_and_counts_each_real_queue_transfer_once(): void
    {
        (require database_path('migrations/2026_11_01_120000_create_hall_queue_transitions_table.php'))->up();
        $id = $this->invoice(['status' => 'pending_finance_reapproval']);
        $key = 'order:invoice:'.$id;
        DB::table('hall_queue_transitions')->insert([
            ['workflow_key'=>$key, 'source_type'=>'invoice', 'source_id'=>$id,
                'document_number'=>'F-test', 'from_stage'=>null, 'to_stage'=>'warehouse',
                'status_from'=>null, 'status_to'=>'pending_collection', 'entered_at'=>now()->subMinutes(15)],
            ['workflow_key'=>$key, 'source_type'=>'invoice', 'source_id'=>$id,
                'document_number'=>'F-test', 'from_stage'=>'warehouse', 'to_stage'=>'reapproval',
                'status_from'=>'pending_collection', 'status_to'=>'pending_finance_reapproval', 'entered_at'=>now()->subMinutes(10)],
            ['workflow_key'=>$key, 'source_type'=>'invoice', 'source_id'=>$id,
                'document_number'=>'F-test', 'from_stage'=>'reapproval', 'to_stage'=>'warehouse',
                'status_from'=>'pending_finance_reapproval', 'status_to'=>'pending_collection', 'entered_at'=>now()->subMinutes(5)],
            ['workflow_key'=>$key, 'source_type'=>'invoice', 'source_id'=>$id,
                'document_number'=>'F-test', 'from_stage'=>'warehouse', 'to_stage'=>'reapproval',
                'status_from'=>'pending_collection', 'status_to'=>'pending_finance_reapproval', 'entered_at'=>now()->subMinute()],
        ]);

        $this->getJson('/hall/history?key='.urlencode($key))->assertForbidden();
        $proof = hash_hmac('sha256', 'hall-management-v1', 'test-manager-key');
        $this->withCredentials()->withCookie('hall_management_access', $proof)
            ->getJson('/hall/history?key='.urlencode($key))
            ->assertOk()->assertJsonPath('transfer_count', 3)
            ->assertJsonPath('record_count', 4)
            ->assertJsonPath('events.0.to_stage', 'reapproval')
            ->assertJsonPath('events.1.to_stage', 'warehouse')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->withCredentials()->withCookie('hall_management_access', $proof)
            ->getJson('/hall/history?key=order%3Ainvoice%3A999999')->assertNotFound();
    }

    public function test_legacy_timeline_keeps_older_status_history_without_fabricating_events(): void
    {
        $orderId = $this->order(['status'=>'converted_to_invoice'], false);
        $invoiceId = $this->invoice(['status'=>'pending_finance_reapproval', 'preinvoice_order_id'=>$orderId]);
        DB::table('warehouse_review_logs')->insert([
            'preinvoice_order_id'=>$orderId, 'status_from'=>'pending_finance',
            'status_to'=>'finance_reviewing', 'created_at'=>now()->subHours(4),
            'updated_at'=>now()->subHours(4),
        ]);
        DB::table('sales_havaleh_histories')->insert([
            'invoice_id'=>$invoiceId, 'field_name'=>'status', 'old_value'=>'pending_collection',
            'new_value'=>'pending_finance_reapproval', 'done_at'=>now()->subHour(),
        ]);
        $proof = hash_hmac('sha256', 'hall-management-v1', 'test-manager-key');
        $key = 'order:'.$orderId;
        $this->withCredentials()->withCookie('hall_management_access', $proof)
            ->getJson('/hall/history?key='.urlencode($key))
            ->assertOk()->assertJsonPath('transfer_count', 2)
            ->assertJsonPath('record_count', 2)
            ->assertJsonPath('events.0.to_stage', 'reapproval')
            ->assertJsonPath('events.1.to_stage', 'finance');
    }

    public function test_lock_revokes_the_scoped_cookie_and_wrong_keys_are_rejected(): void
    {
        $this->post('/hall/unlock', ['access_key' => 'wrong'])->assertSessionHasErrors('access_key');
        $proof = hash_hmac('sha256', 'hall-management-v1', 'test-manager-key');
        $this->withCookie('hall_management_access', $proof)->get('/hall/unlock')
            ->assertOk()->assertCookieExpired('hall_management_access');
    }
}
