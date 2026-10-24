<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountStatementPresentationService
{
    /** One date definition for pagination and balances; never rewrite registration timestamps. */
    public function entries(Customer $customer): Builder
    {
        $datedEntries = CustomerLedger::query()
            ->where('customer_id', $customer->id)
            ->select('customer_ledgers.*')
            ->selectRaw('DATE(COALESCE((SELECT invoice_payments.paid_at FROM invoice_payments
                WHERE invoice_payments.id = customer_ledgers.reference_id
                AND customer_ledgers.reference_type = ?), customer_ledgers.created_at)) AS statement_date', [InvoicePayment::class]);

        return CustomerLedger::query()->fromSub($datedEntries, 'customer_ledgers');
    }

    /** Balances after each entry, in document-date order, including across page boundaries. */
    public function pageBalances(Customer $customer, Collection $rows, Collection $effectiveIds, int $netBalance): array
    {
        if ($rows->isEmpty()) return [];
        $first = $rows->first();
        $newer = $this->entries($customer)->effectiveForBalance()
            ->where(function ($query) use ($first) {
                if ($first->statement_date === null) {
                    $query->whereNotNull('statement_date')->orWhere(function ($sameDay) use ($first) {
                        $sameDay->whereNull('statement_date')
                            ->where(fn ($same) => $this->newerRegistration($same, $first));
                    });
                } else {
                    $query->where('statement_date', '>', $first->statement_date)->orWhere(function ($sameDay) use ($first) {
                        $sameDay->where('statement_date', $first->statement_date)
                            ->where(fn ($same) => $this->newerRegistration($same, $first));
                    });
                }
            })->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount WHEN type = 'credit' THEN -amount ELSE 0 END), 0) AS movement")->value('movement');
        $balance = $netBalance - (int) $newer;
        $balances = [];
        foreach ($rows as $row) {
            $balances[$row->id] = $balance;
            if ($effectiveIds->has($row->id)) {
                $balance -= match ($row->type) {'debit' => (int) $row->amount, 'credit' => -(int) $row->amount, default => 0};
            }
        }
        return $balances;
    }

    private function newerRegistration(Builder $query, CustomerLedger $first): void
    {
        if ($first->created_at === null) {
            $query->whereNotNull('created_at')->orWhere(function ($same) use ($first) {
                $same->whereNull('created_at')->where('id', '>', $first->id);
            });

            return;
        }

        $query->where('created_at', '>', $first->created_at)->orWhere(function ($same) use ($first) {
            $same->where('created_at', $first->created_at)->where('id', '>', $first->id);
        });
    }
}
