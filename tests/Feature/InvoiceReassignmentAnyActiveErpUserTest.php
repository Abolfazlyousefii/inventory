<?php

use App\Http\Controllers\CommercialInvoiceReassignmentController;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SellerReassignmentAudit;
use App\Models\User;
use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('lists active ERP users as invoice transfer destinations even without seller role', function () {
    $manager = User::factory()->create(['name' => 'Manager', 'is_active' => true, 'can_access_erp' => true, 'is_seller' => false]);
    $seller = User::factory()->create(['name' => 'Seller', 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
    $noAccess = User::factory()->create(['name' => 'No access', 'is_active' => true, 'can_access_erp' => false, 'is_seller' => true]);
    $inactive = User::factory()->create(['name' => 'Inactive', 'is_active' => false, 'can_access_erp' => true, 'is_seller' => true]);

    $view = app(CommercialInvoiceReassignmentController::class)->index(Request::create('/commercial/invoice-reassignments'));
    $ids = $view->getData()['sellers']->pluck('id')->all();

    expect($ids)->toContain($manager->id, $seller->id)
        ->not->toContain($noAccess->id, $inactive->id);
});

it('moves invoice ownership to an active ERP manager while preserving financial fields and audit', function () {
    $actor = User::factory()->create();
    $old = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
    $manager = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => false]);
    $order = PreinvoiceOrder::query()->create([
        'uuid' => fake()->uuid(), 'created_by' => $old->id, 'seller_id' => $old->id,
        'status' => 'draft', 'customer_name' => 'Customer', 'customer_mobile' => '09120000000',
        'customer_address' => 'Address', 'province_id' => 1, 'total_price' => 1200,
    ]);
    $invoice = Invoice::query()->create([
        'uuid' => fake()->uuid(), 'preinvoice_order_id' => $order->id,
        'seller_id' => $old->id, 'subtotal' => 1200, 'total' => 1200,
    ]);

    $result = app(SalesDocumentSellerReassignmentService::class)
        ->reassignInvoiceSeller($invoice, $manager, $actor, 'Approved owner change');

    expect($result->changed)->toBeTrue()
        ->and($invoice->fresh()->seller_id)->toBe($manager->id)
        ->and($order->fresh()->seller_id)->toBe($manager->id)
        ->and($order->fresh()->created_by)->toBe($old->id)
        ->and((int) $invoice->fresh()->total)->toBe(1200)
        ->and($invoice->fresh()->commissionSellerId())->toBeNull();
    $this->assertDatabaseHas('seller_reassignment_audits', [
        'invoice_id' => $invoice->id, 'old_seller_id' => $old->id,
        'new_seller_id' => $manager->id, 'changed_by' => $actor->id,
    ]);
});

it('rejects destination users who are inactive or cannot access ERP', function () {
    $actor = User::factory()->create();
    $invoice = Invoice::query()->create(['uuid' => fake()->uuid(), 'total' => 1000, 'subtotal' => 1000]);

    foreach ([
        ['is_active' => false, 'can_access_erp' => true],
        ['is_active' => true, 'can_access_erp' => false],
    ] as $attrs) {
        $user = User::factory()->create($attrs + ['is_seller' => false]);
        try {
            app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $user, $actor, 'invalid');
            $this->fail('Ineligible user was accepted.');
        } catch (ValidationException) {
            expect($invoice->fresh()->seller_id)->toBeNull();
        }
    }
    $this->assertDatabaseCount('seller_reassignment_audits', 0);
});

it('allows a non-seller ERP manager in transfer preview but rejects inactive destinations', function () {
    $actor = User::factory()->create();
    $manager = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => false]);
    $inactive = User::factory()->create(['is_active' => false, 'can_access_erp' => true, 'is_seller' => false]);
    $invoice = Invoice::query()->create(['uuid' => fake()->uuid(), 'subtotal' => 1000, 'total' => 1000]);

    $makeRequest = function (User $destination) use ($invoice, $actor) {
        $request = Request::create('/commercial/invoice-reassignments/preview', 'POST', [
            'invoice_ids' => [$invoice->id], 'seller_id' => $destination->id,
            'reason' => 'test', 'sync_preinvoice' => true,
        ]);
        $request->setUserResolver(fn () => $actor);

        return $request;
    };
    $controller = app(CommercialInvoiceReassignmentController::class);
    $payload = $controller->preview($makeRequest($manager))->getData(true);
    expect($payload['summary']['destination_seller']['id'])->toBe($manager->id);

    $this->expectException(ValidationException::class);
    $controller->preview($makeRequest($inactive));
});
