<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerInvoicePaymentSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerInvoicePaymentSettingsController extends Controller
{
    public function edit(Request $request, CustomerInvoicePaymentSettingsService $settings): View
    {
        $this->authorizeManager($request);

        return view('admin.invoice-payment-settings.edit', [
            'details' => $settings->details(),
            'dbReady' => $settings->databaseReady(),
        ]);
    }

    public function update(Request $request, CustomerInvoicePaymentSettingsService $settings): RedirectResponse
    {
        $this->authorizeManager($request);

        if (! $settings->databaseReady()) {
            return redirect()->route('admin.invoice-payment-settings.edit')
                ->with('error', 'جدول تنظیمات ایجاد نشده است. ابتدا دستور php artisan migrate --force را اجرا کنید.');
        }

        // Normalize presentation separators without converting identifiers into integers.
        $data = $request->all();
        $data['national_id'] = preg_replace('/\\s+/', '', (string) ($data['national_id'] ?? ''));
        $data['iban'] = strtoupper(preg_replace('/[\\s-]+/', '', (string) ($data['iban'] ?? '')));
        $data['account'] = preg_replace('/[\\s-]+/', '', (string) ($data['account'] ?? ''));
        $cardDigits = preg_replace('/[\\s-]+/', '', (string) ($data['card'] ?? ''));
        $data['card'] = preg_match('/^[0-9]{16}$/D', $cardDigits)
            ? implode('-', str_split($cardDigits, 4))
            : $cardDigits;

        $validated = validator($data, [
            'name' => ['nullable', 'string', 'max:150'],
            'national_id' => ['nullable', 'regex:/^[0-9]{10}$/D'],
            'iban' => ['nullable', 'regex:/^IR[0-9]{24}$/D'],
            'account' => ['nullable', 'regex:/^[0-9]{1,30}$/D'],
            'card' => ['nullable', 'regex:/^[0-9]{4}(?:-[0-9]{4}){3}$/D'],
        ], [
            'national_id.regex' => 'شماره ملی باید ۱۰ رقم باشد.',
            'iban.regex' => 'شماره شبا باید با IR شروع شود و ۲۴ رقم بعد از آن داشته باشد.',
            'account.regex' => 'شماره حساب باید فقط شامل ارقام باشد.',
            'card.regex' => 'شماره کارت باید ۱۶ رقم باشد.',
        ])->validate();

        $settings->save(array_map(
            fn ($value) => trim((string) ($value ?? '')),
            array_replace(['name' => '', 'national_id' => '', 'iban' => '', 'account' => '', 'card' => ''], $validated)
        ));

        return redirect()->route('admin.invoice-payment-settings.edit')
            ->with('success', 'اطلاعات واریز فاکتور مشتری ذخیره شد.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('settings.edit'), 403);
    }
}
