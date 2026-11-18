<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ChequeCoverage
{
    public function balance(Collection $entries, string $through): array
    {
        $rows = $entries->filter(fn ($r) => $r->effective_date->format('Y-m-d') <= $through);
        $incoming = (int) $rows->where('kind', 'deposit')->sum('amount');
        $outgoing = (int) $rows->where('kind', 'issued')->sum('amount');

        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'balance' => $incoming - $outgoing, 'shortfall' => max(0, $outgoing - $incoming)];
    }
}
