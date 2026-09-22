<?php

namespace App\Observers;

use App\Events\InvoiceChangeAuditRequested;
use App\Models\CustomerLedger;
use Illuminate\Support\Facades\DB;

class CustomerLedgerChangeAuditObserver
{
    public function created(CustomerLedger $ledger): void
    {
        $after = $this->snapshot($ledger);
        $effect = $this->balanceEffect(null, $after);

        $this->afterCommit('customer_ledger_created', [
            'customer_id' => (int) $ledger->customer_id,
            'customer_ledger_id' => (int) $ledger->id,
            'notes_target' => 'customer_ledgers.note',
            'notes_candidate' => $this->notesCandidate('created', null, $after, $effect),
            'before' => null,
            'after' => $after,
            'customer_balance_effect' => $effect,
        ]);
    }

    public function updated(CustomerLedger $ledger): void
    {
        $tracked = ['customer_id', 'type', 'amount', 'reference_type', 'reference_id', 'note'];
        $changed = array_values(array_intersect(array_keys($ledger->getChanges()), $tracked));
        if ($changed === []) {
            return;
        }

        $before = [
            'id' => (int) $ledger->id,
            'customer_id' => (int) $ledger->getOriginal('customer_id'),
            'type' => (string) $ledger->getOriginal('type'),
            'amount' => (int) $ledger->getOriginal('amount'),
            'reference_type' => $ledger->getOriginal('reference_type'),
            'reference_id' => $ledger->getOriginal('reference_id') !== null ? (int) $ledger->getOriginal('reference_id') : null,
            'note' => $ledger->getOriginal('note'),
        ];
        $after = $this->snapshot($ledger);
        $effect = $this->balanceEffect($before, $after);

        $this->afterCommit('customer_ledger_updated', [
            'customer_id' => (int) $ledger->customer_id,
            'customer_ledger_id' => (int) $ledger->id,
            'changed_fields' => $changed,
            'notes_target' => 'customer_ledgers.note',
            'notes_candidate' => $this->notesCandidate('updated', $before, $after, $effect),
            'before' => $before,
            'after' => $after,
            'customer_balance_effect' => $effect,
        ]);
    }

    public function deleted(CustomerLedger $ledger): void
    {
        $before = $this->snapshot($ledger);
        $effect = $this->balanceEffect($before, null);

        $this->afterCommit('customer_ledger_deleted', [
            'customer_id' => (int) $ledger->customer_id,
            'customer_ledger_id' => (int) $ledger->id,
            'notes_target' => 'customer_ledgers.note / deletion audit',
            'notes_candidate' => $this->notesCandidate('deleted', $before, null, $effect),
            'before' => $before,
            'after' => null,
            'customer_balance_effect' => $effect,
        ]);
    }

    private function notesCandidate(string $operation, ?array $before, ?array $after, array $effect): array
    {
        return [
            'schema_version' => 1,
            'financial_ledger' => [
                'operation' => $operation,
                'type_before' => $before['type'] ?? null,
                'type_after' => $after['type'] ?? null,
                'amount_before' => $before['amount'] ?? 0,
                'amount_after' => $after['amount'] ?? 0,
                'reference_type' => $after['reference_type'] ?? $before['reference_type'] ?? null,
                'reference_id' => $after['reference_id'] ?? $before['reference_id'] ?? null,
                'note_before' => $before['note'] ?? null,
                'note_after' => $after['note'] ?? null,
                'debt_delta' => $effect['debt_delta'],
                'customer_became_more_debtor_by' => $effect['debit_effect_amount'],
                'customer_became_more_creditor_by' => $effect['credit_effect_amount'],
            ],
        ];
    }

    private function balanceEffect(?array $before, ?array $after): array
    {
        $beforeSigned = $this->signedDebt($before);
        $afterSigned = $this->signedDebt($after);
        $delta = $afterSigned - $beforeSigned;

        return [
            'debt_before_effective_component' => $beforeSigned,
            'debt_after_effective_component' => $afterSigned,
            'debt_delta' => $delta,
            'debit_effect_amount' => max($delta, 0),
            'credit_effect_amount' => max(-$delta, 0),
            'meaning' => $delta > 0
                ? 'بدهی خالص مشتری افزایش می‌یابد.'
                : ($delta < 0 ? 'بدهی خالص مشتری کاهش می‌یابد / بستانکاری مؤثر مشتری بیشتر می‌شود.' : 'اثر خالصی روی مانده مشتری ندارد.'),
        ];
    }

    private function signedDebt(?array $row): int
    {
        if ($row === null) {
            return 0;
        }

        $amount = (int) ($row['amount'] ?? 0);
        return ($row['type'] ?? null) === 'credit' ? -$amount : $amount;
    }

    private function snapshot(CustomerLedger $ledger): array
    {
        return [
            'id' => (int) $ledger->id,
            'customer_id' => (int) $ledger->customer_id,
            'type' => (string) $ledger->type,
            'amount' => (int) $ledger->amount,
            'reference_type' => $ledger->reference_type,
            'reference_id' => $ledger->reference_id !== null ? (int) $ledger->reference_id : null,
            'note' => $ledger->note,
        ];
    }

    private function afterCommit(string $action, array $payload): void
    {
        $dispatch = static fn () => InvoiceChangeAuditRequested::dispatch($action, $payload);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
            return;
        }

        $dispatch();
    }
}
