<?php

use App\Models\{ActivityLog, Customer, CustomerLedger, InvoicePayment, PersonnelAccountDocument, User};
use App\Support\AccountStatementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->customer = Customer::factory()->create(['opening_balance' => 1000]);
    $this->actingAs($this->actor);
    $this->payload = ['type' => 'credit', 'document_amount' => '۲۰۰',
        'document_date' => '1405/08/10', 'description' => 'حقوق طبق سند سازه‌حساب',
        'reference' => 'SZ-100', 'submission_token' => (string) Str::uuid()];
});

it('posts credit and debit movements in the existing person account without replacing its balance', function () {
    $this->post(route('account-statements.personnel.store', $this->customer), $this->payload)
        ->assertRedirect(route('account-statements.show', $this->customer))->assertSessionHasNoErrors();
    expect((int) Customer::withBalance()->find($this->customer->id)->balance)->toBe(800);
    $this->post(route('account-statements.personnel.store', $this->customer), array_replace($this->payload,
        ['type' => 'debit', 'document_amount' => 50, 'description' => 'مساعده پرسنلی', 'submission_token' => (string) Str::uuid()]))
        ->assertSessionHasNoErrors();
    expect((int) Customer::withBalance()->find($this->customer->id)->balance)->toBe(850)
        ->and(PersonnelAccountDocument::count())->toBe(2)->and(CustomerLedger::count())->toBe(2)
        ->and(InvoicePayment::count())->toBe(0);
    $this->get(route('account-statements.show', $this->customer))->assertOk()
        ->assertSee('افزودن سند پرسنلی')->assertSee('حقوق طبق سند سازه‌حساب')->assertSee('SZ-100')
        ->assertSee('1405/08/10')->assertSee('PER-1');
    expect(ActivityLog::where('action', 'personnel_account_document_created')->count())->toBe(2);
});

it('retries the same submission exactly once and rejects changed or cross-person replays', function () {
    $url = route('account-statements.personnel.store', $this->customer);
    $this->post($url, $this->payload)->assertSessionHasNoErrors();
    $this->post($url, $this->payload)->assertSessionHasNoErrors();
    $this->post($url, array_replace($this->payload, ['document_amount' => 300]))->assertSessionHasErrors('submission_token');
    $other = Customer::factory()->create();
    $this->post(route('account-statements.personnel.store', $other), $this->payload)->assertSessionHasErrors('submission_token');
    expect(CustomerLedger::count())->toBe(1)->and(PersonnelAccountDocument::count())->toBe(1);
});

it('rejects invalid amounts types dates and missing descriptions without writing', function ($change, $field) {
    $this->post(route('account-statements.personnel.store', $this->customer), array_replace($this->payload, $change))
        ->assertSessionHasErrors($field);
    expect(CustomerLedger::count())->toBe(0)->and(PersonnelAccountDocument::count())->toBe(0);
})->with([
    [['document_amount' => 0], 'document_amount'],
    [['document_amount' => '-100'], 'document_amount'],
    [['document_amount' => '1.5'], 'document_amount'],
    [['document_amount' => '2abc'], 'document_amount'],
    [['document_amount' => '1000000000000001'], 'document_amount'],
    [['type' => 'settled'], 'type'],
    [['document_date' => '1405/13/40'], 'document_date'],
    [['document_date' => '2099-01-01'], 'document_date'],
    [['description' => ''], 'description'],
    [['submission_token' => 'invalid'], 'submission_token'],
]);

it('blocks read-only finance and unprivileged users while keeping the statement visible to readers', function () {
    $reader = User::factory()->create();
    $reader->assignRole(Role::findOrCreate('Manager', 'web'), Role::findOrCreate(AccountStatementAccess::ROLE, 'web'));
    $reader->permissions()->attach(\Illuminate\Support\Facades\DB::table('permissions')->where('key', 'page.finance.accounts')->value('id'));
    $this->actingAs($reader)->get(route('account-statements.show', $this->customer))->assertOk()->assertDontSee('افزودن سند پرسنلی');
    $this->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertForbidden();
    expect(CustomerLedger::count())->toBe(0);
});

it('uses document dates for ordering and preserves recording timestamps and other peoples balances', function () {
    $other = Customer::factory()->create(['opening_balance' => 500]);
    $this->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertSessionHasNoErrors();
    $doc = PersonnelAccountDocument::sole();
    $ledger = CustomerLedger::sole();
    expect($doc->document_date->format('Y-m-d'))->toBe('2026-11-01')
        ->and($ledger->created_at->isToday())->toBeTrue()
        ->and((int) Customer::withBalance()->find($other->id)->balance)->toBe(500);
    $row = app(\App\Services\AccountStatementPresentationService::class)->entries($this->customer)->first();
    expect($row->statement_date)->toBe('2026-11-01');
    $this->get(route('account-statements.show', $this->customer))->assertOk();
    expect($ledger->fresh()->created_at->equalTo($ledger->created_at))->toBeTrue();
});

it('keeps the date in Jalali when redisplaying a rejected form', function () {
    $this->from(route('account-statements.show', $this->customer))->post(route('account-statements.personnel.store', $this->customer),
        array_replace($this->payload, ['_personnel_document' => 'yes', 'description' => '']))->assertSessionHasErrors('description');
    $this->get(route('account-statements.show', $this->customer))->assertOk()
        ->assertSee('data-personnel-reopen', false)->assertSee('value="1405/08/10"', false);
});

it('normalizes grouped Persian amounts and escapes document text', function () {
    $description = '<script>alert(1)</script>';
    $this->post(route('account-statements.personnel.store', $this->customer), array_replace($this->payload,
        ['document_amount' => '۲٬۰۰۰', 'description' => $description]))->assertSessionHasNoErrors();
    expect(PersonnelAccountDocument::sole()->amount)->toBe(2000);
    $this->get(route('account-statements.show', $this->customer))->assertOk()
        ->assertSee(e($description), false)->assertDontSee($description, false);
});

it('rejects another operator replaying a token without duplicating the financial entry', function () {
    $this->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertSessionHasNoErrors();
    $other = User::factory()->create();
    $other->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($other)->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertSessionHasErrors('submission_token');
    expect(CustomerLedger::count())->toBe(1);
});

it('allows a finance operator with the existing account management permission to register a document', function () {
    $writer = User::factory()->create();
    $writer->permissions()->attach(\Illuminate\Support\Facades\DB::table('permissions')->where('key', 'page.finance.accounts')->value('id'));
    $this->actingAs($writer)->get(route('account-statements.show', $this->customer))->assertOk()->assertSee('افزودن سند پرسنلی');
    $this->post(route('account-statements.personnel.store', $this->customer), $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    expect(PersonnelAccountDocument::sole()->created_by)->toBe($writer->id);
});

it('preserves a long description without exceeding the legacy ledger note limit', function () {
    $description = str_repeat('شرح حقوق ', 200);
    $this->post(route('account-statements.personnel.store', $this->customer), array_replace($this->payload,
        ['description' => $description]))->assertSessionHasNoErrors();
    expect(PersonnelAccountDocument::sole()->description)->toBe(trim($description))
        ->and(mb_strlen(CustomerLedger::sole()->note))->toBeLessThanOrEqual(255);
});
