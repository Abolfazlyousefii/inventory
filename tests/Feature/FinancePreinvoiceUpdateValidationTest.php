<?php

use App\Http\Requests\FinanceUpdatePreinvoiceRequest;
use Illuminate\Support\Facades\File;

it('defines Persian edit reason validation messages', function () {
    $request = new FinanceUpdatePreinvoiceRequest();

    expect($request->rules()['edit_reason'])->toContain('required', 'string', 'min:3', 'max:1000')
        ->and($request->messages()['edit_reason.required'])->toBe('لطفاً دلیل ویرایش مالی را وارد کنید.')
        ->and($request->messages()['edit_reason.min'])->toBe('دلیل ویرایش مالی باید حداقل ۳ کاراکتر باشد.')
        ->and($request->attributes()['edit_reason'])->toBe('دلیل ویرایش مالی');
});

it('renders edit reason once next to the textarea inside the finance edit save form', function () {
    $view = File::get(resource_path('views/preinvoice/finance-edit.blade.php'));

    expect($view)->toContain('id="financeEditForm"')
        ->and($view)->toContain('id="edit_reason"')
        ->and($view)->toContain('name="edit_reason"')
        ->and($view)->toContain('required minlength="3" maxlength="1000"')
        ->and(substr_count($view, "@error('edit_reason')"))->toBe(1)
        ->and($view)->toContain("reject(fn($messages, $key) => $key === 'edit_reason'")
        ->and($view)->toContain("old('edit_reason')");
});

it('redirects a successful finance edit back to the finance edit page without finalize coupling', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $start = strpos($controller, 'public function financeUpdate');
    $end = strpos($controller, 'public function finance(string $uuid)', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)->toContain("route('preinvoice.draft.finance.edit'")
                ->and($method)->not->toContain('Invoice::create')
        ->and($method)->not->toContain('InvoiceItem::create');
});
