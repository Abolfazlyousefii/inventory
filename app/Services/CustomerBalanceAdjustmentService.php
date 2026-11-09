<?php

namespace App\Services;

use App\Models\{ActivityLog, Customer, CustomerLedger};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerBalanceAdjustmentService
{
    public function ledger(Customer $customer, ActivityLog $adjustment): CustomerLedger
    {
        abort_unless($adjustment->action === 'customer_balance_adjusted'
            && $adjustment->subject_type === Customer::class
            && (int) $adjustment->subject_id === (int) $customer->id, 404);
        abort_if(data_get($adjustment->properties, 'deleted_at'), 404);
        $rows = CustomerLedger::query()->where('reference_type', ActivityLog::class)
            ->where('reference_id', $adjustment->id)->get();
        $row = $rows->first();
        if ($rows->count() !== 1 || (int) $row->customer_id !== (int) $customer->id
            || !in_array($row->type, ['debit', 'credit'], true)
            || (int) $row->amount <= 0
            || (data_get($adjustment->properties, 'ledger_id') !== null
                && (int) data_get($adjustment->properties, 'ledger_id') !== (int) $row->id)) {
            throw ValidationException::withMessages(['adjustment' => 'سند اصلاحی با گردش حساب هماهنگ نیست؛ ابتدا سوابق بررسی شود.']);
        }
        return $row;
    }

    public function version(ActivityLog $adjustment, CustomerLedger $ledger): string
    {
        return hash('sha256', json_encode([$adjustment->properties,
            $ledger->only(['id', 'customer_id', 'type', 'amount', 'note', 'updated_at'])], JSON_THROW_ON_ERROR));
    }

    public function change(Customer $customer, ActivityLog $adjustment, array $data, bool $delete = false): void
    {
        DB::transaction(function () use ($customer, $adjustment, $data, $delete) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $adjustment = ActivityLog::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            // The customer lock serializes edits to this customer's adjustment documents.
            CustomerLedger::query()->where('reference_type', ActivityLog::class)
                ->where('reference_id', $adjustment->id)->lockForUpdate()->get();
            $ledger = $this->ledger($customer, $adjustment);
            if (!hash_equals($this->version($adjustment, $ledger), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'سند تغییر کرده است؛ صفحه را دوباره باز کنید.']);
            }
            $before = $ledger->only(['id', 'type', 'amount', 'note']);
            $balanceBefore = (int) Customer::query()->withBalance()->findOrFail($customer->id)->balance;
            $properties = $adjustment->properties ?? [];
            // Legacy logs may have no properties. The unique ledger's explicit
            // reference and verified customer identify the existing document.
            if (!isset($properties['ledger_id'])) {
                $properties['ledger_id'] = $ledger->id;
                $properties['legacy_metadata_recovered_at'] = now()->toIso8601String();
                $properties['recovered_ledger_snapshot'] = $before;
            }
            if ($delete) {
                $ledger->delete();
                $properties['deleted_at'] = now()->toIso8601String();
                $properties['deleted_by'] = auth()->id();
            } else {
                $ledger->update(['type' => $data['type'], 'amount' => $data['amount']]);
                $properties['current_type'] = $ledger->type;
                $properties['current_amount'] = (int) $ledger->amount;
            }
            $properties['last_change_reason'] = trim($data['reason']);
            $adjustment->update(['properties' => $properties]);
            ActivityLog::query()->create([
                'user_id' => auth()->id(),
                'action' => $delete ? 'customer_balance_adjustment_deleted' : 'customer_balance_adjustment_updated',
                'subject_type' => Customer::class, 'subject_id' => $customer->id,
                'description' => $delete ? 'سند اصلاح مانده حذف شد.' : 'سند اصلاح مانده ویرایش شد.',
                'properties' => ['adjustment_id' => $adjustment->id, 'before' => $before,
                    'after' => $delete ? null : $ledger->only(['id', 'type', 'amount', 'note']),
                    'balance_before' => $balanceBefore,
                    'balance_after' => (int) Customer::query()->withBalance()->findOrFail($customer->id)->balance,
                    'reason' => trim($data['reason'])], 'occurred_at' => now(),
            ]);
        }, 3);
    }
}
