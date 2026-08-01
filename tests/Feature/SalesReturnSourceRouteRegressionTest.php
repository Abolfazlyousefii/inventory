<?php

use App\Http\Requests\StoreSalesReturnRequest;
use App\Models\SalesReturnDocument;

it('reads the checked source as a scalar and never uses a shadowed form action', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)
        ->toContain('input[name="source_choice"]:checked')
        ->toContain("allowedSources=['internal_invoice','sazeh_hesab']")
        ->toContain("const actionUrl=form.getAttribute('action')")
        ->toContain('fetch(actionUrl,')
        ->not->toContain('fetch(form.action,')
        ->not->toContain('[object RadioNodeList]')
        ->not->toContain('%5Bobject%20RadioNodeList%5D');
});

it('uses the shared safe form for create draft edit and applied edit', function () {
    $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));

    expect(substr_count($controller, "view('vouchers.return-from-sale.create'"))->toBe(3)
        ->and($controller)->toContain(
            'public function create()',
            'public function edit(SalesReturnDocument $document)',
            'public function editApplied(SalesReturnDocument $document)'
        );
});

it('keeps the real route contract and backend source validation', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());
    $rules = (new StoreSalesReturnRequest)->rules();

    expect($routes['vouchers.return-from-sale.store']->uri())->toBe('vouchers/section/return-from-sale')
        ->and($routes['vouchers.return-from-sale.update']->uri())->toBe('vouchers/section/return-from-sale/{document}')
        ->and($routes['vouchers.return-from-sale.applied.update']->uri())->toBe('vouchers/section/return-from-sale/{document}/applied-update')
        ->and($routes['vouchers.return-from-sale.show']->wheres['document'] ?? null)->toBe('[0-9]+')
        ->and($rules['source_type'])->toContain('required')
        ->and(SalesReturnDocument::sourceTypeLabels())->toHaveKeys(['internal_invoice', 'sazeh_hesab']);
});

it('marks non-submit controls explicitly and submit intents remain compatible', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain(
        'type="button" id="openAddItem"',
        'type="button" id="clearForm"',
        'type="submit" name="action" value="draft"',
        'type="submit" name="action" value="apply"'
    );
});
