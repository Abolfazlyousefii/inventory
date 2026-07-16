<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Services\SalesDocumentAccessService;

it('allows an owner to edit supported preinvoice states without an invoice', function (string $status) {
    $user = new User;
    $user->id = 108;

    $order = new PreinvoiceOrder;
    $order->created_by = 108;
    $order->status = $status;
    $order->setRelation('invoice', null);

    expect(app(SalesDocumentAccessService::class)->canSellerEditPreinvoiceItems($order, $user))->toBeTrue();
})->with([
    PreinvoiceOrder::STATUS_DRAFT,
    PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
    PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
]);

it('locks a draft which already has an invoice', function () {
    $user = new User;
    $user->id = 108;

    $order = new PreinvoiceOrder;
    $order->created_by = 108;
    $order->status = PreinvoiceOrder::STATUS_DRAFT;
    $order->setRelation('invoice', new Invoice);

    expect(app(SalesDocumentAccessService::class)->canSellerEditPreinvoiceItems($order, $user))->toBeFalse();
});

it('does not allow an anonymous or different seller to edit a draft', function () {
    $order = new PreinvoiceOrder;
    $order->created_by = 108;
    $order->status = PreinvoiceOrder::STATUS_DRAFT;
    $order->setRelation('invoice', null);

    expect(app(SalesDocumentAccessService::class)->canSellerEditPreinvoiceItems($order, null))->toBeFalse();
});

it('renders both seller draft actions and explicit submit intents', function () {
    $workspace = file_get_contents(resource_path('views/preinvoice/my-index.blade.php'));
    $editor = file_get_contents(resource_path('views/preinvoice/create.blade.php'));

    expect($workspace)
        ->toContain("['primary_action_url']")
        ->toContain("['secondary_action_url']")
        ->toContain("['primary_action_label']")
        ->toContain("['secondary_action_label']")
        ->and($editor)
        ->toContain('name="intent" value="draft"')
        ->toContain('name="intent" value="submit"')
        ->toContain('ثبت نهایی و ارسال به مالی')
        ->not->toContain('route(\'preinvoice.draft.finalize\'');
});
