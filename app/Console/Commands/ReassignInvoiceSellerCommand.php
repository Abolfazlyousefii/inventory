<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Console\Command;

class ReassignInvoiceSellerCommand extends Command
{
    protected $signature = 'sales:reassign-invoice-seller {--invoice=*} {--input=} {--from-seller=} {--to-seller=} {--actor=} {--reason=} {--sync-preinvoice} {--dry-run} {--apply}';
    protected $description = 'Safely preview or apply invoice seller reassignment (dry-run by default)';

    public function handle(SalesDocumentSellerReassignmentService $service): int
    {
        if (! $this->option('to-seller') || trim((string) $this->option('reason')) === '') return $this->rejectCommand('--to-seller و --reason الزامی هستند.');
        if ($this->option('apply') && $this->option('dry-run')) return $this->rejectCommand('--apply و --dry-run هم‌زمان مجاز نیستند.');
        $seller = User::query()->find($this->option('to-seller'));
        if (! $seller || ! $seller->is_active || ! $seller->can_access_erp || ! $seller->is_seller) return $this->rejectCommand('فروشنده مقصد معتبر و فعال نیست.');
        $ids = array_values(array_filter(array_map('intval', $this->option('invoice'))));
        if ($path = $this->option('input')) {
            if (! is_file($path)) return $this->rejectCommand('فایل ورودی یافت نشد.');
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $first = trim(str_getcsv($line)[0] ?? '');
                if (ctype_digit($first)) $ids[] = (int) $first;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > 100) return $this->rejectCommand('بین ۱ تا ۱۰۰ شناسه فاکتور لازم است.');
        $query = Invoice::query()->with(['seller:id,name', 'preinvoiceOrder:id,seller_id'])->whereIn('id', $ids);
        if ($this->option('from-seller')) $query->where('seller_id', (int) $this->option('from-seller'));
        $invoices = $query->get();
        if ($invoices->count() !== count($ids)) return $this->rejectCommand('همه فاکتورها یافت نشدند یا با --from-seller منطبق نیستند.');
        $this->table(['Invoice', 'Current seller', 'New seller', 'Preinvoice'], $invoices->map(fn ($i) => [$i->id, $i->seller?->name.' (#'.$i->seller_id.')', $seller->name.' (#'.$seller->id.')', $i->preinvoice_order_id ?: '—'])->all());
        if (! $this->option('apply')) { $this->info('DRY RUN: هیچ تغییری نوشته نشد.'); return self::SUCCESS; }
        $actor = User::query()->find($this->option('actor'));
        if (! $actor) return $this->rejectCommand('برای Apply، --actor با users.id معتبر الزامی است.');
        if ($this->input->isInteractive() && ! $this->confirm('اعمال تغییرات تأیید می‌شود؟')) return self::FAILURE;
        $service->reassignMany($ids, $seller, $actor, (string) $this->option('reason'), (bool) $this->option('sync-preinvoice'), 'command');
        $this->info(count($ids).' فاکتور تغییر کرد.');
        return self::SUCCESS;
    }

    private function rejectCommand(string $message): int { $this->error($message); return self::FAILURE; }
}
