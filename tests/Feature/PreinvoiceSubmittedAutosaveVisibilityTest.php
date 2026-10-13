<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\MySalesDocumentsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreinvoiceSubmittedAutosaveVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Schema::create('preinvoice_orders', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status');
            $table->boolean('is_auto_draft')->nullable();
            $table->timestamp('document_date')->nullable();
            $table->timestamps();
        });
        Schema::create('preinvoice_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preinvoice_order_id');
            $table->integer('quantity');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->unsignedBigInteger('preinvoice_order_id');
            $table->string('status');
            $table->timestamp('items_updated_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_non_draft_autosaves_follow_current_status_and_seller_ownership(): void
    {
        $service = app(MySalesDocumentsService::class);
        foreach ([
            ['pending_finance', null, 'active'],
            ['converted_to_invoice', 'pending_collection', 'active'],
            ['converted_to_invoice', 'shipped', 'shipped'],
            ['returned_to_sales', null, 'needs-correction'],
        ] as $index => [$status, $invoiceStatus, $tab]) {
            $order = PreinvoiceOrder::create(['uuid' => '0194'.$index, 'created_by' => 115, 'seller_id' => 115, 'status' => $status, 'is_auto_draft' => true]);
            if ($invoiceStatus) {
                Invoice::create(['uuid' => $order->uuid, 'preinvoice_order_id' => $order->id, 'status' => $invoiceStatus]);
            }
            $query = fn (int $seller) => $service->applyBucket($service->baseQuery($seller), $service->tabToBucket($tab))->whereKey($order->id);
            $this->assertTrue($query(115)->exists(), $status.' must remain visible');
            $this->assertFalse($query(116)->exists(), 'Another seller must not see it');
            $this->assertFalse($service->applyBucket($service->baseQuery(115, true), 'draft')->whereKey($order->id)->exists());
        }
        $this->assertSame(['active' => 2, 'drafts' => 0, 'shipped' => 1, 'needs-correction' => 1], $service->counts(115));
    }

    public function test_only_actual_temporary_drafts_are_excluded_and_creator_fallback_is_preserved(): void
    {
        $temporary = PreinvoiceOrder::create(['uuid' => 'TEMP', 'created_by' => 115, 'status' => 'draft', 'is_auto_draft' => true]);
        $saved = PreinvoiceOrder::create(['uuid' => 'SAVED', 'created_by' => 115, 'status' => 'draft', 'is_auto_draft' => false]);
        $legacy = PreinvoiceOrder::create(['uuid' => 'LEGACY', 'created_by' => 115, 'status' => 'draft', 'is_auto_draft' => null]);
        $submitted = PreinvoiceOrder::create(['uuid' => 'SUBMITTED', 'created_by' => 115, 'status' => 'pending_finance', 'is_auto_draft' => true]);
        $this->assertSame([$saved->id, $legacy->id, $submitted->id], PreinvoiceOrder::createdBySeller(115)->withoutTemporaryAutosaves()->orderBy('id')->pluck('id')->all());
        $service = app(MySalesDocumentsService::class);
        $this->assertSame(3, $service->counts(115)['drafts']);
        $this->assertSame(1, $service->counts(115)['active']);
        $this->assertFalse($service->applyBucket($service->baseQuery(115), 'active')->whereKey($temporary->id)->exists());
    }
}
