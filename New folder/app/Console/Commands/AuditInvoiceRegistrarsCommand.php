<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuditInvoiceRegistrarsCommand extends Command
{
    protected $signature = 'sales:audit-invoice-registrars {--user=139 : Internal users.id to inspect}';

    protected $description = 'Read-only audit of invoice registrar coverage through linked preinvoices';

    public function handle(): int
    {
        $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $userId) {
            $this->error('--user باید یک users.id مثبت باشد.');

            return self::FAILURE;
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'database_status' => 'ready',
            'registrar_contract' => 'preinvoice_orders.created_by -> users.id',
            'invoice_direct_registrar_column' => null,
            'inspected_user_id' => $userId,
        ];

        if (! $this->schemaIsReady()) {
            $report['database_status'] = 'unavailable';
            $report['error'] = 'Required users, invoices, or preinvoice_orders table is missing.';
            $this->writeReport($report);
            $this->error('ساختار دیتابیس در اتصال فعلی کامل نیست؛ Audit بدون هیچ Write متوقف شد.');

            return self::FAILURE;
        }

        $directRegistrarColumn = collect(['registered_by', 'created_by', 'user_id'])
            ->first(fn (string $column) => Schema::hasColumn('invoices', $column));
        $report['invoice_direct_registrar_column'] = $directRegistrarColumn;

        $attributableInvoices = DB::table('invoices as i')
            ->join('preinvoice_orders as p', 'p.id', '=', 'i.preinvoice_order_id')
            ->join('users as registrar', 'registrar.id', '=', 'p.created_by');

        $report += [
            'active_erp_users' => User::query()->activeErpUsers()->count(),
            'dropdown_users' => User::query()->activeErpUsers()->count(),
            'invoices_total' => DB::table('invoices')->count(),
            'invoices_with_seller_id' => DB::table('invoices')->whereNotNull('seller_id')->count(),
            'invoices_with_direct_registrar' => $directRegistrarColumn
                ? DB::table('invoices')->whereNotNull($directRegistrarColumn)->count()
                : 0,
            'invoices_with_preinvoice' => DB::table('invoices')->whereNotNull('preinvoice_order_id')->count(),
            'preinvoices_with_valid_registrar' => DB::table('preinvoice_orders as p')
                ->join('users as registrar', 'registrar.id', '=', 'p.created_by')
                ->count(),
            'invoices_attributable_via_preinvoice' => (clone $attributableInvoices)->count(),
            'invoices_without_valid_registrar' => DB::table('invoices as i')
                ->leftJoin('preinvoice_orders as p', 'p.id', '=', 'i.preinvoice_order_id')
                ->leftJoin('users as registrar', 'registrar.id', '=', 'p.created_by')
                ->whereNull('registrar.id')
                ->count(),
            'seller_registrar_mismatches' => DB::table('invoices as i')
                ->join('preinvoice_orders as p', 'p.id', '=', 'i.preinvoice_order_id')
                ->whereNotNull('i.seller_id')
                ->whereNotNull('p.created_by')
                ->whereColumn('i.seller_id', '<>', 'p.created_by')
                ->count(),
            'crm_id_confusion_in_seller_id' => DB::table('invoices as i')
                ->whereNotNull('i.seller_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('users as internal_user')->whereColumn('internal_user.id', 'i.seller_id'))
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('users as crm_user')->whereColumn('crm_user.crm_user_id', 'i.seller_id'))
                ->count(),
            'invoices_for_inspected_user' => (clone $attributableInvoices)
                ->where('p.created_by', $userId)
                ->count(),
            'direct_invoices_without_preinvoice' => DB::table('invoices')->whereNull('preinvoice_order_id')->count(),
        ];

        $this->writeReport($report);
        $this->line(collect($report)->map(fn ($value, $key) => $key . ': ' . ($value ?? 'null'))->implode(PHP_EOL));

        return self::SUCCESS;
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasTable('invoices')
            && Schema::hasTable('preinvoice_orders');
    }

    private function writeReport(array $report): void
    {
        File::ensureDirectoryExists(storage_path('logs'));
        File::put(
            storage_path('logs/invoice-registrar-audit.json'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        File::put(
            storage_path('logs/invoice-registrar-audit.txt'),
            collect($report)->map(fn ($value, $key) => $key . ': ' . ($value ?? 'null'))->implode(PHP_EOL) . PHP_EOL
        );
    }
}
