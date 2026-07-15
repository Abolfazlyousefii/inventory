<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Invoice::query()
            ->with(['items' => fn ($query) => $query->withoutGlobalScopes()->reorder()->orderBy('id')])
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $order = 1;

                    foreach ($invoice->items as $item) {
                        if ($item->sort_order === null) {
                            $item->sort_order = $order;
                            $item->saveQuietly();
                        }

                        $order++;
                    }
                }
            });
    }

    public function down(): void
    {
        // Data backfill only; do not erase existing document ordering on rollback.
    }
};
