<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use Illuminate\Support\Collection;

class AccountStatementPresentationService
{
    /** Balances for the existing newest-first page. Does not post or modify entries. */
    public function pageBalances(Customer $customer, Collection $rows, Collection $effectiveIds, int $netBalance): array
    {
        if ($rows->isEmpty()) return [];
        $first = $rows->first();
        $newer = CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)
            ->where(function ($query) use ($first) {
                if ($first->created_at === null) {
                    $query->whereNotNull('created_at')->orWhere(function ($same) use ($first) {
                        $same->whereNull('created_at')->where('id', '>', $first->id);
                    });
                } else {
                    $query->where('created_at', '>', $first->created_at)->orWhere(function ($same) use ($first) {
                        $same->where('created_at', $first->created_at)->where('id', '>', $first->id);
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
}
