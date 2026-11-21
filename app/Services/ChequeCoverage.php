<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ChequeCoverage
{
    public function balance(Collection $entries, string $through): array
    {
        $rows = $entries->filter(fn ($r) => $r->calendarDate() <= $through);
        $incoming = (int) $rows->where('kind', 'deposit')->reject(fn($e)=>$e->isBounced())->sum('amount');
        $outgoing = (int) $rows->whereIn('kind', ['issued','promise'])->sum('amount');

        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'balance' => $incoming - $outgoing, 'shortfall' => max(0, $outgoing - $incoming)];
    }
}
