<?php

namespace App\Services;

use App\Models\CustomerInvoicePaymentSetting;
use Illuminate\Support\Facades\Schema;

class CustomerInvoicePaymentSettingsService
{
    private const FIELDS = ['name', 'national_id', 'iban', 'account', 'card'];

    public function databaseReady(): bool
    {
        return Schema::hasTable('customer_invoice_payment_settings');
    }

    /** @return array{name: string, national_id: string, iban: string, account: string, card: string} */
    public function details(): array
    {
        $config = config('customer_invoice_payment', []);
        $fallback = [];
        foreach (self::FIELDS as $field) {
            $fallback[$field] = trim((string) ($config[$field] ?? ''));
        }

        // Existing installations keep printing from private .env until migrate is run.
        if (! $this->databaseReady()) {
            return $fallback;
        }

        $record = CustomerInvoicePaymentSetting::query()->find(1);

        if (! $record) {
            return $fallback;
        }

        $details = [];
        foreach (self::FIELDS as $field) {
            $details[$field] = trim((string) ($record->{$field} ?? ''));
        }

        return $details;
    }

    public function save(array $details): void
    {
        CustomerInvoicePaymentSetting::query()->updateOrCreate(['id' => 1], $details);
    }
}
