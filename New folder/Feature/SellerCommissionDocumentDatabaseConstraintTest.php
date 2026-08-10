<?php

namespace Tests\Feature;

use App\Http\Controllers\SellerCommissionDocumentController;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentDatabaseConstraintTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_existing_document_tables_are_reused_with_snapshot_columns(): void
    {
        $this->assertTrue(Schema::hasTable('seller_sales_documents'));
        $this->assertTrue(Schema::hasTable('seller_sales_document_items'));
        $this->assertTrue(Schema::hasColumns('seller_sales_document_items', ['invoice_id', 'invoice_number_snapshot', 'invoice_date_snapshot', 'customer_name_snapshot', 'invoice_total_snapshot']));
    }

    public function test_database_unique_constraint_rejects_duplicate_invoice_id(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $first = $this->createCommissionDocument($owner, [$invoice], $actor);
        $second = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $item = $first->items()->first();
        $this->expectException(QueryException::class);
        SellerSalesDocumentItem::query()->create(['seller_sales_document_id' => $second->id, 'invoice_id' => $invoice->id, 'invoice_number_snapshot' => $item->invoice_number_snapshot, 'invoice_date_snapshot' => $item->invoice_date_snapshot, 'customer_name_snapshot' => $item->customer_name_snapshot, 'invoice_total_snapshot' => $item->invoice_total_snapshot]);
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
