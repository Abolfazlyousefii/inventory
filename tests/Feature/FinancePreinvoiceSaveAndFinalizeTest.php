<?php

use Illuminate\Support\Facades\File;

it('save and finalize updates first then refreshes before finalizing', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $start = strpos($controller, 'public function financeSaveAndFinalize');
    $end = strpos($controller, 'public function finance(string $uuid)', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)->toContain('financePreinvoiceEditorService->update')
        ->and($method)->toContain("refresh()->load('items')")
        ->and($method)->toContain('return $this->finalize');
});
