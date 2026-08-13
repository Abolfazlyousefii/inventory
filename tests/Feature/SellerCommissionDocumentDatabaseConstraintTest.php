<?php

namespace Tests\Feature;

use App\Http\Controllers\SellerCommissionDocumentController;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentDatabaseConstraintTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_clean_database_has_reassignment_columns_indexes_and_original_invoice_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('seller_sales_documents'));
        $this->assertTrue(Schema::hasTable('seller_sales_document_items'));
        $this->assertTrue(Schema::hasColumns('seller_sales_document_items', [
            'invoice_id',
            'status',
            'active_invoice_id',
            'invoice_number_snapshot',
            'invoice_date_snapshot',
            'customer_name_snapshot',
            'invoice_total_snapshot',
            'reassigned_to_seller_id',
            'reassigned_at',
            'reassignment_audit_id',
        ]));

        $indexes = collect(Schema::getIndexes('seller_sales_document_items'))->keyBy('name');
        $this->assertTrue($indexes->has('ssd_items_invoice_id_index'));
        $this->assertFalse($indexes['ssd_items_invoice_id_index']['unique']);
        $this->assertSame(['invoice_id'], $indexes['ssd_items_invoice_id_index']['columns']);
        $this->assertFalse($indexes->has('seller_sales_document_items_invoice_unique'));
        $this->assertTrue($indexes['ssd_items_active_invoice_unique']['unique']);
        $this->assertSame(['active_invoice_id'], $indexes['ssd_items_active_invoice_unique']['columns']);
        $this->assertTrue(collect(Schema::getForeignKeys('seller_sales_document_items'))
            ->contains(fn (array $foreign): bool => ($foreign['columns'] ?? []) === ['invoice_id']));
    }

    public function test_database_unique_constraint_rejects_two_active_rows_for_an_invoice(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $first = $this->createCommissionDocument($owner, [$invoice], $actor);
        $second = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $item = $first->items()->first();
        $this->expectException(QueryException::class);
        SellerSalesDocumentItem::query()->create(['seller_sales_document_id' => $second->id, 'invoice_id' => $invoice->id, 'active_invoice_id' => $invoice->id, 'status' => SellerSalesDocumentItem::STATUS_ACTIVE, 'invoice_number_snapshot' => $item->invoice_number_snapshot, 'invoice_date_snapshot' => $item->invoice_date_snapshot, 'customer_name_snapshot' => $item->customer_name_snapshot, 'invoice_total_snapshot' => $item->invoice_total_snapshot]);
    }

    public function test_historical_row_allows_a_new_active_row_for_same_invoice(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $first = $this->createCommissionDocument($owner, [$invoice], $actor);
        $item = $first->items()->firstOrFail();
        $item->update(['status' => SellerSalesDocumentItem::STATUS_REASSIGNED, 'active_invoice_id' => null]);
        $second = $this->createCommissionDocument($owner, [$invoice], $actor);

        $this->assertSame(2, SellerSalesDocumentItem::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame($invoice->id, $second->items()->firstOrFail()->active_invoice_id);
    }

    public function test_reassignment_migration_is_idempotent_after_partial_execution(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $activeInvoice = $this->makeInvoice($owner);
        $historicalInvoice = $this->makeInvoice($owner);
        $document = $this->createCommissionDocument($owner, [$activeInvoice, $historicalInvoice], $actor);
        $historicalItem = $document->items()->where('invoice_id', $historicalInvoice->id)->firstOrFail();
        $historicalItem->update([
            'status' => SellerSalesDocumentItem::STATUS_REASSIGNED,
            'active_invoice_id' => null,
            'reassigned_to_seller_id' => $owner->id,
        ]);

        Schema::table('seller_sales_document_items', function (Blueprint $table): void {
            $table->dropUnique('ssd_items_active_invoice_unique');
            $table->dropIndex('ssd_items_status_idx');
            $table->dropIndex('ssd_items_invoice_id_index');
            $table->unique('invoice_id', 'seller_sales_document_items_invoice_unique');
        });

        $migration = require database_path('migrations/2026_08_13_120000_add_reassignment_history_to_seller_sales_document_items.php');
        $migration->up();
        $migration->up();

        $indexes = collect(Schema::getIndexes('seller_sales_document_items'))->keyBy('name');
        $this->assertTrue($indexes->has('ssd_items_invoice_id_index'));
        $this->assertFalse($indexes['ssd_items_invoice_id_index']['unique']);
        $this->assertSame(['invoice_id'], $indexes['ssd_items_invoice_id_index']['columns']);
        $this->assertFalse($indexes->has('seller_sales_document_items_invoice_unique'));
        $this->assertTrue($indexes['ssd_items_active_invoice_unique']['unique']);
        $this->assertSame(['active_invoice_id'], $indexes['ssd_items_active_invoice_unique']['columns']);
        $this->assertSame($activeInvoice->id, $document->items()->where('invoice_id', $activeInvoice->id)->firstOrFail()->active_invoice_id);
        $this->assertNull($historicalItem->fresh()->active_invoice_id);
        $this->assertSame(SellerSalesDocumentItem::STATUS_REASSIGNED, $historicalItem->fresh()->status);
        $this->assertTrue(collect(Schema::getForeignKeys('seller_sales_document_items'))
            ->contains(fn (array $foreign): bool => ($foreign['columns'] ?? []) === ['invoice_id']));
    }

    public function test_document_numbers_are_automatic_unique_and_use_sc_format(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $first = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $second = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $this->assertMatchesRegularExpression('/^SC-\d{6}$/', $first->document_number);
        $this->assertNotSame($first->document_number, $second->document_number);
    }

    public function test_only_eight_non_destructive_module_routes_exist(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'finance.seller-sales.'));
        $this->assertCount(8, $routes);
        $this->assertFalse($routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true)));
    }

    public function test_controller_views_and_model_expose_no_delete_or_soft_delete_contract(): void
    {
        $this->assertFalse(method_exists(SellerCommissionDocumentController::class, 'destroy'));
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(SellerSalesDocument::class));

        foreach (['index', 'show', 'form'] as $view) {
            $contents = file_get_contents(resource_path("views/finance/seller-commission-documents/{$view}.blade.php"));
            $this->assertStringNotContainsString('finance.seller-sales.destroy', $contents);
            $this->assertStringNotContainsString('@method(\'DELETE\')', $contents);
        }
    }

    public function test_commission_amount_or_percentage_columns_do_not_exist(): void
    {
        $this->assertFalse(Schema::hasColumn('seller_sales_documents', 'commission_amount'));
        $this->assertFalse(Schema::hasColumn('seller_sales_documents', 'commission_percentage'));
        $this->assertFalse(Schema::hasColumn('seller_sales_document_items', 'commission_amount'));
    }
}
