<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\FinancialAccountDocument;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use App\Services\FinancialAccountDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('edits only its posting and cancels once while preserving financial audit history', function () {
    $customer = Customer::factory()->create(['opening_balance' => 500]);
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasNoErrors();
    $doc = FinancialAccountDocument::sole();
    $edit = $this->documentPayload;
    $edit['document_amount'] = 2000;
    $edit['type'] = 'credit';
    $edit['version'] = 1;
    $this->get(route('financial-documents.edit', $doc))->assertOk()->assertSee('ویرایش سند مالی');
    $this->put(route('financial-documents.update', $doc), $edit)->assertSessionHasNoErrors();
    expect($customer->fresh()->balance)->toBe(-1500)->and(CustomerLedger::count())->toBe(1);
    $this->put(route('financial-documents.update', $doc), $edit)->assertSessionHasErrors('version');
    $this->delete(route('financial-documents.destroy', $doc), ['version' => 1, 'reason' => 'لغو سند اشتباه'])->assertSessionHasErrors('version');
    $this->delete(route('financial-documents.destroy', $doc), ['version' => 2, 'reason' => 'لغو سند اشتباه'])->assertSessionHasNoErrors();
    expect($customer->fresh()->balance)->toBe(500)->and($doc->fresh()->cancelled_at)->not->toBeNull()
        ->and(ActivityLog::where('action', 'financial_account_document_updated')->count())->toBe(1)
        ->and(ActivityLog::where('action', 'financial_account_document_cancelled')->count())->toBe(1);
    $this->delete(route('financial-documents.destroy', $doc), ['version' => 2, 'reason' => 'لغو سند اشتباه'])->assertSessionHasErrors();
    expect($customer->fresh()->balance)->toBe(500);
});

it('updates supplier documents without changing a customer with the same phone and rejects missing descriptions', function () {
    $customer = Customer::factory()->create(['mobile' => '09124445566']);
    $supplier = Supplier::create(['name' => 'تأمین‌کننده', 'phone' => $customer->mobile]);
    $this->post(route('persons.financial-documents.store', 'supplier-only-'.$supplier->id), $this->documentPayload)->assertSessionHasNoErrors();
    $doc = FinancialAccountDocument::sole();
    $edit = array_merge($this->documentPayload, ['version' => 1, 'document_amount' => 800]);
    $this->put(route('financial-documents.update', $doc), array_merge($edit, ['description' => '']))->assertSessionHasErrors('description');
    $this->put(route('financial-documents.update', $doc), $edit)->assertSessionHasNoErrors();
    expect((int) SupplierLedger::sum('amount'))->toBe(800)->and($customer->fresh()->balance)->toBe(0);
    $this->delete(route('financial-documents.destroy', $doc), ['version' => 2])->assertSessionHasErrors('reason');
    $this->get(route('persons.account', 'supplier-only-'.$supplier->id))->assertSee(route('financial-documents.edit', $doc), false);
});

it('blocks read-only editing and deletion on the server', function () {
    $customer = Customer::factory()->create();
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasNoErrors();
    $doc = FinancialAccountDocument::sole();
    $reader = User::factory()->create();
    $reader->assignRole(Role::findOrCreate('Org:finance.account_statements_readonly', 'web'));
    $reader->givePermissionTo(Permission::where('key', 'page.finance.account_statements_readonly')->firstOrFail());
    $this->actingAs($reader);
    $this->get(route('financial-documents.edit', $doc))->assertForbidden();
    $this->put(route('financial-documents.update', $doc), $this->documentPayload + ['version' => 1])->assertForbidden();
    $this->delete(route('financial-documents.destroy', $doc), ['version' => 1, 'reason' => 'حذف آزمایشی'])->assertForbidden();
    expect($doc->fresh()->version)->toBe(1)->and($customer->fresh()->balance)->toBe(1250000);
});

it('rolls back edits and cancellations when their audit cannot be saved', function ($cancel) {
    $customer = Customer::factory()->create();
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasNoErrors();
    $doc = FinancialAccountDocument::sole();
    ActivityLog::creating(function ($log) {
        if (in_array($log->action, ['financial_account_document_updated', 'financial_account_document_cancelled'])) {
            throw new RuntimeException('revision audit unavailable');
        }
    });
    $this->withoutExceptionHandling();
    try {
        app(FinancialAccountDocumentService::class)->revise($doc, array_merge($this->documentPayload,
            ['version' => 1, 'document_amount' => 800, 'reason' => 'لغو آزمایشی']), $this->actor->id, $cancel);
        $this->fail('Expected failed audit');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('revision audit unavailable');
    }
    expect($customer->fresh()->balance)->toBe(1250000)->and($doc->fresh()->version)->toBe(1)
        ->and($doc->fresh()->cancelled_at)->toBeNull();
})->with([false, true]);

it('does not modify mismatched postings or move the document to another account', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasNoErrors();
    $doc = FinancialAccountDocument::sole();
    $edit = array_merge($this->documentPayload, ['version' => 1, 'document_amount' => 900, 'customer_id' => $other->id]);
    $this->put(route('financial-documents.update', $doc), $edit)->assertSessionHasNoErrors();
    expect($doc->fresh()->customer_id)->toBe($customer->id)->and($other->fresh()->balance)->toBe(0);
    CustomerLedger::query()->update(['amount' => 700]);
    $edit['version'] = 2;
    $this->put(route('financial-documents.update', $doc), $edit)->assertSessionHasErrors('document');
    expect((int) CustomerLedger::sum('amount'))->toBe(700)->and($doc->fresh()->version)->toBe(2);
});

beforeEach(function () {
    $this->withoutVite();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($this->actor);
    $this->documentPayload = ['title' => 'اصلاح توافق حساب', 'description' => 'طبق توافق و تأیید مسئول مالی',
        'type' => 'debit', 'document_amount' => '۱٬۲۵۰٬۰۰۰', 'document_date' => '1405/08/01', 'submission_token' => (string) Str::uuid()];
});

it('posts one titled customer document and retries without duplicating or replacing its balance', function () {
    $customer = Customer::factory()->create(['opening_balance' => 500]);
    $url = route('account-statements.financial-documents.store', $customer);
    $this->post($url, $this->documentPayload)->assertSessionHasNoErrors()->assertRedirect(route('account-statements.show', $customer));
    $this->post($url, $this->documentPayload)->assertSessionHasNoErrors();
    expect(DB::table('financial_account_documents')->count())->toBe(1)
        ->and(CustomerLedger::where('customer_id', $customer->id)->count())->toBe(1)
        ->and($customer->fresh()->balance)->toBe(1250500);
    $page = $this->get(route('account-statements.show', $customer));
    $page->assertOk()->assertSee('اصلاح توافق حساب')->assertSee('طبق توافق و تأیید مسئول مالی');
    expect($page->viewData('ledgers')->first()->statement_date)->toBe('2026-10-23');
    $changed = $this->documentPayload;
    $changed['document_amount'] = 20;
    $this->post($url, $changed)->assertSessionHasErrors('submission_token');
    expect($customer->fresh()->balance)->toBe(1250500);
});

it('posts a supplier credit only to that supplier even when its phone belongs to a customer', function () {
    $customer = Customer::factory()->create(['mobile' => '09121234567', 'opening_balance' => 300]);
    $supplier = Supplier::create(['name' => 'تأمین‌کننده سند مالی', 'phone' => $customer->mobile]);
    $this->documentPayload['type'] = 'credit';
    $this->post(route('persons.financial-documents.store', 'supplier-only-'.$supplier->id), $this->documentPayload)
        ->assertSessionHasNoErrors()->assertRedirect(route('persons.account', 'supplier-only-'.$supplier->id));
    expect(SupplierLedger::where('supplier_id', $supplier->id)->count())->toBe(1)
        ->and(CustomerLedger::where('customer_id', $customer->id)->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(300);
    $page = $this->get(route('persons.account', 'supplier-only-'.$supplier->id));
    $page->assertOk()->assertSee('اصلاح توافق حساب')->assertSee('طبق توافق و تأیید مسئول مالی');
    expect($page->viewData('netBalance'))->toBe(-1250000)
        ->and($page->viewData('ledgers')->first()->statement_date)->toBe('2026-10-23');
});

it('requires title description positive amount and a submission token', function ($field, $value) {
    $customer = Customer::factory()->create();
    $this->documentPayload[$field] = $value;
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasErrors($field);
    expect(CustomerLedger::count())->toBe(0)->and(DB::table('financial_account_documents')->count())->toBe(0);
})->with([['title', '   '], ['description', ''], ['document_amount', 0], ['document_amount', -10], ['submission_token', null], ['type', 'invalid']]);

it('does not let a read-only finance role create a document or see its popup', function () {
    $reader = User::factory()->create();
    $reader->assignRole(Role::findOrCreate('Org:finance.account_statements_readonly', 'web'));
    $reader->givePermissionTo(Permission::where('key', 'page.finance.account_statements_readonly')->firstOrFail());
    $this->actingAs($reader);
    $customer = Customer::factory()->create();
    $this->get(route('account-statements.show', $customer))->assertOk()->assertDontSee('id="financialDocumentModal"', false);
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertForbidden();
    expect(DB::table('financial_account_documents')->count())->toBe(0);
});

it('requires an explicit book for a person with both roles and posts to only the chosen book', function () {
    $customer = Customer::factory()->create(['mobile' => '09121112233']);
    $supplier = Supplier::create(['name' => 'شخص مشترک مالی', 'phone' => $customer->mobile]);
    $url = route('persons.financial-documents.store', $customer->mobile);
    $this->post($url, $this->documentPayload)->assertSessionHasErrors('account_role');
    expect(CustomerLedger::count())->toBe(0)->and(SupplierLedger::count())->toBe(0);
    $this->documentPayload['account_role'] = 'supplier';
    $this->post($url, $this->documentPayload)->assertSessionHasNoErrors();
    expect(SupplierLedger::where('supplier_id', $supplier->id)->sum('amount'))->toBe(1250000)
        ->and(CustomerLedger::where('customer_id', $customer->id)->count())->toBe(0);
    $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload)->assertSessionHasErrors('account_role');
});

it('rejects reuse of a document token for another person without moving its balance', function () {
    $first = Customer::factory()->create();
    $second = Customer::factory()->create();
    $this->post(route('account-statements.financial-documents.store', $first), $this->documentPayload)->assertSessionHasNoErrors();
    $this->post(route('account-statements.financial-documents.store', $second), $this->documentPayload)->assertSessionHasErrors('submission_token');
    expect($second->fresh()->balance)->toBe(0)->and(DB::table('financial_account_documents')->count())->toBe(1);
});

it('rolls back both source and ledger if the financial audit cannot be saved', function () {
    $customer = Customer::factory()->create();
    $this->withoutExceptionHandling();
    ActivityLog::creating(function ($log) {
        if ($log->action === 'financial_account_document_created') {
            throw new RuntimeException('audit unavailable');
        }
    });
    try {
        $this->post(route('account-statements.financial-documents.store', $customer), $this->documentPayload);
        $this->fail('Expected failed audit');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('audit unavailable');
    }
    expect(DB::table('financial_account_documents')->count())->toBe(0)->and(CustomerLedger::count())->toBe(0);
});
