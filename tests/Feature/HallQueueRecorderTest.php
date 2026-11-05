<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Observers\HallQueueObserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HallQueueRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_01_120000_create_hall_queue_transitions_table.php'))->up();
    }

    private function changedOrder(string $old, string $new): PreinvoiceOrder
    {
        $model = new PreinvoiceOrder;
        $model->setRawAttributes(['id' => 123, 'status' => $old], true);
        $model->status = $new;
        $model->syncChanges();

        return $model;
    }

    public function test_queue_entry_records_only_after_commit_and_rollback_leaves_no_event(): void
    {
        $observer = app(HallQueueObserver::class);
        DB::beginTransaction();
        $observer->updated($this->changedOrder('draft', 'pending_finance'));
        $this->assertSame(0, DB::table('hall_queue_transitions')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('hall_queue_transitions')->count());
        DB::transaction(fn () => $observer->updated($this->changedOrder('draft', 'pending_finance')));
        $this->assertSame(1, DB::table('hall_queue_transitions')->count());
        $this->assertSame('reserved', DB::table('hall_queue_transitions')->value('to_stage'));
    }

    public function test_captured_status_survives_later_model_updates_before_commit(): void
    {
        $model = $this->changedOrder('draft', 'pending_finance');
        DB::transaction(function () use ($model) {
            app(HallQueueObserver::class)->updated($model);
            $model->syncOriginal();
            $model->total_price = 987;
            $model->syncChanges();
        });
        $this->assertSame('pending_finance', DB::table('hall_queue_transitions')->value('status_to'));
    }

    public function test_internal_finance_status_changes_and_converted_parent_do_not_reset_queue(): void
    {
        $observer = app(HallQueueObserver::class);
        $observer->updated($this->changedOrder('warehouse_approved_waiting_finance', 'finance_reviewing'));
        $observer->updated($this->changedOrder('pending_finance', 'converted_to_invoice'));
        $this->assertSame(0, DB::table('hall_queue_transitions')->count());
    }

    public function test_new_invoice_uses_original_workflow_identity(): void
    {
        $model = new Invoice;
        $model->setRawAttributes(['id' => 456, 'preinvoice_order_id' => 123, 'status' => 'pending_collection'], true);
        app(HallQueueObserver::class)->created($model);
        $row = DB::table('hall_queue_transitions')->first();
        $this->assertSame('order:123', $row->workflow_key);
        $this->assertSame('warehouse', $row->to_stage);
    }

    public function test_reapproval_transition_is_its_own_stage_and_keeps_parent_workflow_key(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 456, 'preinvoice_order_id' => 123,
            'status' => 'pending_collection', 'uuid' => 'F-456',
        ], true);
        $invoice->status = Invoice::STATUS_PENDING_FINANCE_REAPPROVAL;
        $invoice->syncChanges();

        DB::transaction(fn () => app(HallQueueObserver::class)->updated($invoice));

        $event = DB::table('hall_queue_transitions')->first();
        $this->assertSame(1, DB::table('hall_queue_transitions')->count());
        $this->assertSame('order:123', $event->workflow_key);
        $this->assertSame('warehouse', $event->from_stage);
        $this->assertSame('reapproval', $event->to_stage);
        $this->assertSame('pending_finance_reapproval', $event->status_to);
    }

    public function test_deleted_invoice_retains_its_number_without_requiring_the_original_row(): void
    {
        $model = new Invoice;
        $model->setRawAttributes(['id' => 456, 'uuid' => 'F-789', 'status' => 'collecting'], true);
        app(HallQueueObserver::class)->deleted($model);
        $row = DB::table('hall_queue_transitions')->first();
        $this->assertSame('deleted', $row->status_to);
        $this->assertSame('F-789', $row->document_number);
        $this->assertNull($row->to_stage);
    }

    public function test_optional_missing_tracking_table_cannot_interrupt_sales(): void
    {
        Schema::drop('hall_queue_transitions');
        app(HallQueueObserver::class)->updated($this->changedOrder('draft', 'pending_finance'));
        $this->assertFalse(Schema::hasTable('hall_queue_transitions'));
    }

    public function test_timestamp_write_failure_does_not_undo_business_commit(): void
    {
        Schema::create('business_markers', fn (Blueprint $table) => $table->id());
        Schema::table('hall_queue_transitions', fn (Blueprint $table) => $table->dropColumn('status_to'));
        DB::transaction(function () {
            DB::table('business_markers')->insert(['id' => 1]);
            app(HallQueueObserver::class)->updated($this->changedOrder('draft', 'pending_finance'));
        });
        $this->assertSame(1, DB::table('business_markers')->count());
        $this->assertSame(0, DB::table('hall_queue_transitions')->count());
    }

    public function test_real_eloquent_create_and_update_capture_final_queue_without_a_worker(): void
    {
        Schema::create('preinvoice_orders', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->dateTime('document_date')->nullable();
            $table->timestamps();
        });
        $original = PreinvoiceOrder::getEventDispatcher();
        try {
            PreinvoiceOrder::setEventDispatcher(new Dispatcher($this->app));
            PreinvoiceOrder::observe(HallQueueObserver::class);
            DB::transaction(function () {
                $order = PreinvoiceOrder::create(['status' => 'draft']);
                $order->update(['status' => 'pending_finance']);
                $this->assertSame(0, DB::table('hall_queue_transitions')->count());
            });
            $this->assertSame(2, DB::table('hall_queue_transitions')->count());
            $this->assertSame('reserved', DB::table('hall_queue_transitions')->orderByDesc('id')->value('to_stage'));
        } finally {
            PreinvoiceOrder::setEventDispatcher($original);
        }
    }
}
