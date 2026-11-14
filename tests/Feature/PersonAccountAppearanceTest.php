<?php

use App\Models\{Customer, Supplier, SupplierLedger, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('presents supplier and customer books with the same document layout and compact actions', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($owner)->withoutVite();
    $supplier = Supplier::create(['name' => 'تأمین‌کننده ظاهر مشترک']);
    SupplierLedger::create(['supplier_id'=>$supplier->id, 'type'=>'credit', 'amount'=>125000, 'note'=>'خرید نمونه']);
    $before = SupplierLedger::count();
    $supplierPage = $this->get(route('persons.account', 'supplier-only-'.$supplier->id));
    $customerPage = $this->get(route('account-statements.show', Customer::factory()->create()));
    foreach ([$supplierPage, $customerPage] as $page) {
        $page->assertOk()->assertSee('css/account-statement-document.css', false)
            ->assertSee('class="as-document"', false)->assertSee('class="asd-toolbar"', false)
            ->assertSee('class="asd-summary"', false)->assertSee('class="asd-table"', false);
    }
    $supplierPage->assertSee('data-bs-target="#supplierPaymentModal"', false)
        ->assertSee('type="date" name="transaction_date"', false)
        ->assertDontSee('type="datetime-local"', false);
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$supplierPage->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//div[@id="supplierPaymentModal"]//form')->length)->toBe(1)
        ->and($xpath->query('//form[not(ancestor::div[@id="supplierPaymentModal"])]//input[@name="amount"]')->length)->toBe(0)
        ->and(SupplierLedger::count())->toBe($before)
        ->and($supplierPage->viewData('netBalance'))->toBe(-125000);
});
