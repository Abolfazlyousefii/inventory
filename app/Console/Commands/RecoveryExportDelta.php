<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class RecoveryExportDelta extends Command
{
    protected $signature = 'recovery:export-delta
        {--hours= : Number of hours to look back}
        {--since= : Absolute start datetime (takes precedence over --hours)}
        {--output= : Optional output root directory}
        {--label=unknown : Source database label, for example local or online}';

    protected $description = 'Export a read-only recovery delta with complete invoice, stock, warehouse, and financial relations';

    private const CHANGE_TIMESTAMPS = [
        'created_at', 'updated_at', 'deleted_at', 'cancelled_at',
        'applied_at', 'received_at', 'approved_at', 'voided_at',
    ];

    /** Tables whose own recent changes are recovery-relevant. */
    private const DELTA_TABLES = [
        'invoices', 'invoice_items', 'customers', 'customer_phones', 'customer_ledgers',
        'invoice_payments', 'cheques', 'invoice_notes', 'invoice_attachments',
        'sales_return_documents', 'sales_return_document_items', 'sales_return_document_revisions',
        'stock_movements', 'warehouse_location_movements',
        'warehouse_transfers', 'warehouse_transfer_items', 'sales_havaleh_histories',
        'invoice_edit_audits', 'invoice_collection_revisions', 'invoice_collection_revision_items',
        'warehouse_inbound_receipts', 'warehouse_inbound_receipt_items', 'activity_logs',
        'seller_reassignment_audits', 'finance_commission_batches', 'finance_commission_batch_items',
        'seller_sales_documents', 'seller_sales_document_items',
        'commission_ledger_entries', 'commission_calculation_warnings',
        'commission_documents', 'commission_document_items', 'commission_document_events',
        'commission_document_corrections', 'commission_correction_entries',
        'commission_reconciliation_warnings', 'commission_adjustments',
        'commission_document_adjustments', 'commission_settlements', 'commission_payments',
        'commission_period_events', 'commission_periods', 'commission_campaigns',
        'commission_campaign_targets', 'commission_rate_revisions', 'commission_settings',
        'commission_targets', 'warehouses',
    ];

    /** Current-state data that must never be treated as an importable delta. */
    private const SNAPSHOT_TABLES = [
        'warehouse_stocks' => 'warehouse_stocks_snapshot.json',
        'warehouse_location_stocks' => 'warehouse_location_stocks_snapshot.json',
        'products' => 'products_snapshot.json',
        'product_variants' => 'variants_snapshot.json',
    ];

    /** Aggregate edges are traversed in both directions, then fully snapshotted. */
    private const AGGREGATE_EDGES = [
        ['invoices', 'invoice_items', 'invoice_id'],
        ['invoices', 'invoice_payments', 'invoice_id'],
        ['invoices', 'invoice_notes', 'invoice_id'],
        ['invoices', 'invoice_attachments', 'invoice_id'],
        ['invoices', 'sales_havaleh_histories', 'invoice_id'],
        ['invoices', 'invoice_edit_audits', 'invoice_id'],
        ['invoices', 'invoice_collection_revisions', 'invoice_id'],
        ['invoices', 'seller_reassignment_audits', 'invoice_id'],
        ['invoices', 'finance_commission_batch_items', 'invoice_id'],
        ['invoices', 'seller_sales_document_items', 'invoice_id'],
        ['invoices', 'commission_ledger_entries', 'invoice_id'],
        ['invoices', 'commission_calculation_warnings', 'invoice_id'],
        ['invoices', 'commission_document_items', 'invoice_id'],
        ['invoices', 'commission_correction_entries', 'invoice_id'],
        ['invoices', 'commission_reconciliation_warnings', 'invoice_id'],
        ['invoices', 'sales_return_documents', 'invoice_id'],
        ['invoices', 'warehouse_transfers', 'related_invoice_id'],
        ['invoice_payments', 'cheques', 'invoice_payment_id'],
        ['invoice_collection_revisions', 'invoice_collection_revision_items', 'revision_id'],
        ['sales_return_documents', 'sales_return_document_items', 'document_id'],
        ['sales_return_documents', 'sales_return_document_revisions', 'document_id'],
        ['sales_return_documents', 'commission_correction_entries', 'sales_return_document_id'],
        ['sales_return_documents', 'commission_reconciliation_warnings', 'sales_return_document_id'],
        ['warehouse_transfers', 'warehouse_transfer_items', 'warehouse_transfer_id'],
        ['warehouse_inbound_receipts', 'warehouse_inbound_receipt_items', 'receipt_id'],
        ['finance_commission_batches', 'finance_commission_batch_items', 'batch_id'],
        ['seller_sales_documents', 'seller_sales_document_items', 'seller_sales_document_id'],
        ['commission_documents', 'commission_document_items', 'commission_document_id'],
        ['commission_documents', 'commission_document_events', 'commission_document_id'],
        ['commission_documents', 'commission_document_corrections', 'commission_document_id'],
        ['commission_documents', 'commission_document_adjustments', 'commission_document_id'],
        ['commission_documents', 'commission_settlements', 'commission_document_id'],
        ['commission_settlements', 'commission_payments', 'commission_settlement_id'],
        ['commission_adjustments', 'commission_document_adjustments', 'commission_adjustment_id'],
    ];

    private const OPTIONAL_TABLES = [
        'customer_phones', 'cheques', 'invoice_notes', 'invoice_attachments',
        'sales_return_documents', 'sales_return_document_items', 'sales_return_document_revisions',
        'warehouse_location_movements', 'warehouse_transfers', 'warehouse_transfer_items',
        'sales_havaleh_histories', 'invoice_edit_audits', 'invoice_collection_revisions',
        'invoice_collection_revision_items', 'warehouse_inbound_receipts',
        'warehouse_inbound_receipt_items', 'activity_logs', 'seller_reassignment_audits',
        'finance_commission_batches', 'finance_commission_batch_items', 'seller_sales_documents',
        'seller_sales_document_items', 'commission_ledger_entries',
        'commission_calculation_warnings', 'commission_documents', 'commission_document_items',
        'commission_document_events', 'commission_document_corrections',
        'commission_correction_entries', 'commission_reconciliation_warnings',
        'commission_adjustments', 'commission_document_adjustments', 'commission_settlements',
        'commission_payments', 'commission_period_events', 'warehouse_location_stocks',
        'commission_periods', 'commission_campaigns', 'commission_campaign_targets',
        'commission_rate_revisions', 'commission_settings', 'commission_targets',
        'preinvoice_orders', 'preinvoice_order_items', 'preinvoice_order_reviews',
        'preinvoice_draft_reservations',
    ];

    /** @var array<string, array<int, true>> */
    private array $selected = [];

    /** @var array<string, array<int, string>> */
    private array $columnNames = [];

    /** @var array<string, array<string, string>> */
    private array $columnTypes = [];

    /** @var array<int, string> */
    private array $warnings = [];

    private bool $enforceReadOnly = false;

    private string $effectiveCutoff;

    public function handle(Filesystem $files): int
    {
        $cutoff = $this->resolveRequestedCutoff();
        if ($cutoff === null) {
            return self::FAILURE;
        }

        foreach (['invoices', 'invoice_items', 'customers'] as $required) {
            if (! Schema::hasTable($required)) {
                $this->error("Required recovery table {$required} is not present.");

                return self::FAILURE;
            }
        }

        $this->installReadOnlyGuard();

        try {
            $clock = $this->databaseClock();
            $databaseOffset = $this->offsetTimezone((int) ($clock['database_utc_offset_seconds'] ?? 0));
            $effective = $cutoff->setTimezone($databaseOffset);
            $this->effectiveCutoff = $effective->format('Y-m-d H:i:s');

            $generatedAt = CarbonImmutable::now(config('app.timezone'));
            [$directory, $zipPath, $stamp, $label] = $this->prepareOutputPaths($files, $generatedAt);

            $this->line('<info>Recovery Delta Export</info>');
            $this->line('Source: '.$label);
            $this->line('Cutoff: '.$this->effectiveCutoff);
            $this->newLine();

            $this->discoverAndSelectRows();
            $this->propagateRelations();

            $counts = [];
            $tableDetails = [];
            $writtenTables = [];
            $fileChecksums = [];

            foreach ($this->exportableTables() as $table) {
                $ids = array_keys($this->selected[$table] ?? []);
                if (! $this->tableExists($table)) {
                    continue;
                }

                $fileName = self::SNAPSHOT_TABLES[$table] ?? ($table.'.json');
                $path = $directory.DIRECTORY_SEPARATOR.$fileName;
                $count = $this->writeTableJson($table, $ids, $path);
                $counts[$table] = $count;
                $writtenTables[] = $table;
                $fileChecksums[$fileName] = hash_file('sha256', $path);
                $tableDetails[$table] = [
                    'file' => $fileName,
                    'count' => $count,
                    'snapshot_only' => isset(self::SNAPSHOT_TABLES[$table]),
                    'change_timestamp_columns' => $this->changeTimestampColumns($table),
                ];
            }

            $snapshotTables = array_values(array_intersect(array_keys(self::SNAPSHOT_TABLES), $writtenTables));
            $auditTables = array_values(array_intersect([
                'activity_logs', 'invoice_edit_audits', 'invoice_collection_revisions',
                'sales_return_document_revisions', 'seller_reassignment_audits',
                'sales_havaleh_histories',
            ], $writtenTables));

            $manifest = [
                'project' => 'inventory',
                'export_type' => 'recovery_delta',
                'source_label' => $label,
                'generated_at' => $generatedAt->toIso8601String(),
                'export_generated_at' => $generatedAt->toIso8601String(),
                'requested_cutoff' => [
                    'since' => $this->option('since'),
                    'hours' => $this->option('hours'),
                    'interpreted_in_application_timezone' => $cutoff->toIso8601String(),
                ],
                'cutoff' => $this->effectiveCutoff,
                'effective_cutoff' => $effective->toIso8601String(),
                'app_timezone' => (string) config('app.timezone'),
                'application_timezone' => (string) config('app.timezone'),
                'database_now' => $clock['database_now'] ?? null,
                'database_utc_now' => $clock['database_utc_now'] ?? null,
                'database_session_timezone' => $clock['database_session_timezone'] ?? null,
                'database_global_timezone' => $clock['database_global_timezone'] ?? null,
                'database_system_timezone' => $clock['database_system_timezone'] ?? null,
                'database_utc_offset_seconds' => (int) ($clock['database_utc_offset_seconds'] ?? 0),
                'database_driver' => DB::connection()->getDriverName(),
                'hostname' => gethostname() ?: null,
                'app_env' => app()->environment(),
                'APP_ENV' => app()->environment(),
                'invoice_number_column' => $this->hasColumn('invoices', 'uuid') ? 'uuid' : null,
                'counts' => $counts,
                'tables' => $writtenTables,
                'table_details' => $tableDetails,
                'snapshot_only_tables' => $snapshotTables,
                'hard_delete_detection' => $auditTables === []
                    ? 'not_available_from_current_database'
                    : 'partially_available_via_audit_and_revision_tables; unaudited_hard_deletes_not_available',
                'hard_delete_evidence_tables' => $auditTables,
                'file_sha256' => $fileChecksums,
                'read_only_database_access' => true,
                'warnings' => array_values(array_unique($this->warnings)),
            ];

            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            $files->put($manifestPath, $this->encodeJson($manifest).PHP_EOL);
            $this->createZip($directory, $zipPath);

            $this->printSummary($counts, $zipPath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Recovery export failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->enforceReadOnly = false;
        }
    }

    private function resolveRequestedCutoff(): ?CarbonImmutable
    {
        $since = trim((string) $this->option('since'));
        $hours = trim((string) $this->option('hours'));
        $timezone = (string) config('app.timezone', 'UTC');

        if ($since === '' && $hours === '') {
            $this->error('At least one of --hours or --since is required.');

            return null;
        }

        try {
            if ($since !== '') {
                return CarbonImmutable::parse($since, $timezone);
            }

            if (! ctype_digit($hours) || (int) $hours <= 0) {
                $this->error('--hours must be a positive whole number.');

                return null;
            }

            return CarbonImmutable::now($timezone)->subHours((int) $hours);
        } catch (Throwable) {
            $this->error('--since must be a valid date and time.');

            return null;
        }
    }

    private function installReadOnlyGuard(): void
    {
        $this->enforceReadOnly = true;

        DB::listen(function (QueryExecuted $query): void {
            if (! $this->enforceReadOnly) {
                return;
            }

            $sql = ltrim(preg_replace('/^(?:\/\*.*?\*\/\s*)+/s', '', $query->sql) ?? $query->sql);
            if (! preg_match('/^(select|show|describe|desc|explain|pragma)\b/i', $sql)) {
                throw new RuntimeException('Blocked non-read database statement during recovery export: '.strtok($sql, " \t\r\n"));
            }
        });
    }

    /** @return array<string, mixed> */
    private function databaseClock(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return (array) DB::selectOne(<<<'SQL'
                SELECT NOW() AS database_now,
                       UTC_TIMESTAMP() AS database_utc_now,
                       @@session.time_zone AS database_session_timezone,
                       @@global.time_zone AS database_global_timezone,
                       @@system_time_zone AS database_system_timezone,
                       TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS database_utc_offset_seconds
            SQL);
        }

        if ($driver === 'sqlite') {
            $this->warnings[] = 'SQLite exposes no separate session/global timezone; UTC is reported.';
            $row = (array) DB::selectOne('SELECT CURRENT_TIMESTAMP AS database_now, CURRENT_TIMESTAMP AS database_utc_now');

            return $row + [
                    'database_session_timezone' => 'UTC',
                    'database_global_timezone' => 'UTC',
                    'database_system_timezone' => 'UTC',
                    'database_utc_offset_seconds' => 0,
                ];
        }

        $this->warnings[] = "Database timezone metadata is limited for driver {$driver}.";
        $row = (array) DB::selectOne('SELECT CURRENT_TIMESTAMP AS database_now');

        return $row + [
                'database_utc_now' => null,
                'database_session_timezone' => null,
                'database_global_timezone' => null,
                'database_system_timezone' => null,
                'database_utc_offset_seconds' => 0,
            ];
    }

    private function offsetTimezone(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** @return array{string, string, string, string} */
    private function prepareOutputPaths(Filesystem $files, CarbonImmutable $generatedAt): array
    {
        $rootOption = trim((string) $this->option('output'));
        $root = $rootOption === '' ? storage_path('app/recovery') : $this->absolutePath($rootOption);
        $label = strtolower(trim((string) $this->option('label')) ?: 'unknown');
        $label = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $label), '-');
        $label = $label === '' ? 'unknown' : $label;
        $stamp = $generatedAt->format('Ymd-His');

        if (! $files->isDirectory($root) && ! $files->makeDirectory($root, 0750, true)) {
            throw new RuntimeException("Cannot create output directory {$root}.");
        }

        $suffix = '';
        $attempt = 0;
        do {
            $directory = $root.DIRECTORY_SEPARATOR.'delta-'.$stamp.$suffix;
            $zipPath = $root.DIRECTORY_SEPARATOR.'recovery-delta-'.$label.'-'.$stamp.$suffix.'.zip';
            $attempt++;
            $suffix = '-'.$attempt;
        } while ($files->exists($directory) || $files->exists($zipPath));

        if (! $files->makeDirectory($directory, 0750, true)) {
            throw new RuntimeException("Cannot create export directory {$directory}.");
        }

        return [$directory, $zipPath, $stamp, $label];
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path)) {
            return rtrim($path, '\\/');
        }

        return base_path(trim($path, '\\/'));
    }

    private function discoverAndSelectRows(): void
    {
        foreach (self::OPTIONAL_TABLES as $table) {
            if (! $this->tableExists($table)) {
                $this->warnings[] = "table {$table} not present";
            }
        }

        foreach (self::DELTA_TABLES as $table) {
            if ($this->tableExists($table)) {
                $this->selectChangedRows($table);
            }
        }

        foreach (array_keys(self::SNAPSHOT_TABLES) as $table) {
            if ($this->tableExists($table)) {
                $this->selectChangedRows($table);
            }
        }
    }

    private function selectChangedRows(string $table): void
    {
        $timestamps = $this->changeTimestampColumns($table);
        if ($timestamps === []) {
            return;
        }

        $query = DB::table($table)->where(function (Builder $query) use ($timestamps): void {
            foreach ($timestamps as $column) {
                $query->orWhere($column, '>=', $this->effectiveCutoff);
            }
        });
        $this->addQueryIds($table, $query);
    }

    private function propagateRelations(): void
    {
        for ($iteration = 0; $iteration < 12; $iteration++) {
            $before = $this->selectedCount();

            foreach (self::AGGREGATE_EDGES as [$parent, $child, $foreignKey]) {
                $this->traverseAggregate($parent, $child, $foreignKey);
            }

            $this->promoteForeignReferences();
            $this->traversePolymorphicRelations();
            $this->selectPreinvoiceContext();
            $this->selectInventorySnapshots();

            if ($before === $this->selectedCount()) {
                return;
            }
        }

        $this->warnings[] = 'Relation expansion reached its safety iteration limit.';
    }

    private function traverseAggregate(string $parent, string $child, string $foreignKey): void
    {
        if (! $this->tableExists($parent) || ! $this->tableExists($child) || ! $this->hasColumn($child, $foreignKey)) {
            return;
        }

        $parentIds = $this->ids($parent);
        if ($parentIds !== []) {
            $this->addWhereIn($child, $foreignKey, $parentIds);
        }

        $childIds = $this->ids($child);
        if ($childIds !== []) {
            $this->addForeignValuesFromSelected($child, $foreignKey, $parent);
        }
    }

    private function promoteForeignReferences(): void
    {
        foreach ([
            ['invoices', 'customer_id', 'customers'],
            ['sales_return_documents', 'customer_id', 'customers'],
            ['invoice_payments', 'customer_id', 'customers'],
            ['customer_ledgers', 'customer_id', 'customers'],
            ['warehouse_transfers', 'customer_id', 'customers'],
            ['sales_return_documents', 'default_destination_warehouse_id', 'warehouses'],
            ['warehouse_transfer_items', 'product_id', 'products'],
            ['warehouse_transfer_items', 'product_variant_id', 'product_variants'],
            ['warehouse_inbound_receipt_items', 'product_id', 'products'],
            ['warehouse_inbound_receipt_items', 'product_variant_id', 'product_variants'],
            ['warehouse_inbound_receipt_items', 'suggested_warehouse_id', 'warehouses'],
            ['warehouse_inbound_receipt_items', 'received_warehouse_id', 'warehouses'],
            ['stock_movements', 'product_id', 'products'],
            ['stock_movements', 'product_variant_id', 'product_variants'],
            ['stock_movements', 'warehouse_id', 'warehouses'],
            ['invoice_items', 'product_id', 'products'],
            ['invoice_items', 'variant_id', 'product_variants'],
            ['sales_return_document_items', 'product_id', 'products'],
            ['sales_return_document_items', 'product_variant_id', 'product_variants'],
            ['sales_return_document_items', 'destination_warehouse_id', 'warehouses'],
            ['warehouse_transfers', 'from_warehouse_id', 'warehouses'],
            ['warehouse_transfers', 'to_warehouse_id', 'warehouses'],
            ['warehouse_inbound_receipt_items', 'stock_movement_id', 'stock_movements'],
            ['commission_ledger_entries', 'commission_period_id', 'commission_periods'],
            ['commission_calculation_warnings', 'commission_period_id', 'commission_periods'],
            ['commission_documents', 'commission_period_id', 'commission_periods'],
            ['commission_correction_entries', 'commission_period_id', 'commission_periods'],
            ['commission_reconciliation_warnings', 'commission_period_id', 'commission_periods'],
            ['commission_adjustments', 'commission_period_id', 'commission_periods'],
            ['commission_settlements', 'commission_period_id', 'commission_periods'],
            ['commission_period_events', 'commission_period_id', 'commission_periods'],
        ] as [$source, $foreignKey, $target]) {
            $this->addForeignValuesFromSelected($source, $foreignKey, $target);
        }

        if ($this->tableExists('customers') && $this->tableExists('customer_phones') && $this->hasColumn('customer_phones', 'customer_id')) {
            $this->addWhereIn('customer_phones', 'customer_id', $this->ids('customers'));
        }
    }

    private function traversePolymorphicRelations(): void
    {
        $mappings = [
            ['customer_ledgers', 'reference_type', 'reference_id', [
                'invoices' => ['App\\Models\\Invoice', 'Invoice', 'invoice'],
                'invoice_payments' => ['App\\Models\\InvoicePayment', 'InvoicePayment', 'invoice_payment'],
                'warehouse_transfers' => ['App\\Models\\WarehouseTransfer', 'WarehouseTransfer', 'warehouse_transfer'],
                'sales_return_documents' => ['App\\Models\\SalesReturnDocument', 'SalesReturnDocument', 'sales_return'],
                'sales_return_document_revisions' => ['App\\Models\\SalesReturnDocumentRevision', 'SalesReturnDocumentRevision'],
            ]],
            ['stock_movements', 'reference_type', 'reference_id', [
                'invoices' => ['App\\Models\\Invoice', 'Invoice', 'invoice'],
                'warehouse_transfers' => ['App\\Models\\WarehouseTransfer', 'WarehouseTransfer', 'warehouse_transfer'],
                'sales_return_documents' => ['App\\Models\\SalesReturnDocument', 'SalesReturnDocument', 'sales_return'],
                'sales_return_document_items' => ['App\\Models\\SalesReturnDocumentItem', 'SalesReturnDocumentItem'],
                'warehouse_inbound_receipt_items' => ['App\\Models\\WarehouseInboundReceiptItem', 'WarehouseInboundReceiptItem'],
            ]],
            ['warehouse_location_movements', 'reference_type', 'reference_id', [
                'invoices' => ['App\\Models\\Invoice', 'Invoice', 'invoice'],
                'warehouse_transfers' => ['App\\Models\\WarehouseTransfer', 'WarehouseTransfer'],
                'sales_return_documents' => ['App\\Models\\SalesReturnDocument', 'SalesReturnDocument'],
            ]],
            ['activity_logs', 'subject_type', 'subject_id', [
                'invoices' => ['App\\Models\\Invoice', 'Invoice'],
                'sales_return_documents' => ['App\\Models\\SalesReturnDocument', 'SalesReturnDocument'],
                'warehouse_transfers' => ['App\\Models\\WarehouseTransfer', 'WarehouseTransfer'],
                'warehouse_inbound_receipts' => ['App\\Models\\WarehouseInboundReceipt', 'WarehouseInboundReceipt'],
            ]],
        ];

        foreach ($mappings as [$relationTable, $typeColumn, $idColumn, $targets]) {
            if (! $this->tableExists($relationTable)
                || ! $this->hasColumn($relationTable, $typeColumn)
                || ! $this->hasColumn($relationTable, $idColumn)) {
                continue;
            }

            foreach ($targets as $targetTable => $types) {
                $targetIds = $this->ids($targetTable);
                if ($targetIds !== []) {
                    foreach (array_chunk($targetIds, 500) as $chunk) {
                        $this->addQueryIds($relationTable, DB::table($relationTable)
                            ->whereIn($typeColumn, $types)
                            ->whereIn($idColumn, $chunk));
                    }
                }

                foreach ($this->selectedRows($relationTable, [$typeColumn, $idColumn]) as $row) {
                    if (in_array((string) ($row->{$typeColumn} ?? ''), $types, true)) {
                        $this->addIds($targetTable, [$row->{$idColumn} ?? null]);
                    }
                }
            }
        }

        $this->traverseInboundSources();
    }

    private function traverseInboundSources(): void
    {
        $table = 'warehouse_inbound_receipts';
        if (! $this->tableExists($table) || ! $this->hasColumn($table, 'source_type') || ! $this->hasColumn($table, 'source_id')) {
            return;
        }

        $sourceMap = [
            'sales_return' => 'sales_return_documents',
            'invoice_cancel' => 'invoices',
            'invoice_adjustment' => 'invoices',
            'finance_adjustment' => 'invoices',
        ];

        foreach ($sourceMap as $type => $target) {
            $targetIds = $this->ids($target);
            if ($targetIds !== []) {
                foreach (array_chunk($targetIds, 500) as $chunk) {
                    $this->addQueryIds($table, DB::table($table)->where('source_type', $type)->whereIn('source_id', $chunk));
                }
            }
        }

        foreach ($this->selectedRows($table, ['source_type', 'source_id']) as $row) {
            $target = $sourceMap[(string) ($row->source_type ?? '')] ?? null;
            if ($target !== null) {
                $this->addIds($target, [$row->source_id ?? null]);
            }
        }
    }

    private function selectPreinvoiceContext(): void
    {
        if (! $this->tableExists('preinvoice_orders') || ! $this->hasColumn('invoices', 'preinvoice_order_id')) {
            return;
        }

        $this->addForeignValuesFromSelected('invoices', 'preinvoice_order_id', 'preinvoice_orders');
        $preinvoiceIds = $this->ids('preinvoice_orders');

        foreach ([
            ['preinvoice_order_items', 'preinvoice_order_id'],
            ['preinvoice_order_reviews', 'preinvoice_order_id'],
            ['preinvoice_draft_reservations', 'preinvoice_order_id'],
        ] as [$table, $foreignKey]) {
            if ($this->tableExists($table) && $this->hasColumn($table, $foreignKey)) {
                $this->addWhereIn($table, $foreignKey, $preinvoiceIds);
            }
        }
    }

    private function selectInventorySnapshots(): void
    {
        foreach (['products', 'product_variants', 'warehouses'] as $table) {
            if (! isset($this->selected[$table])) {
                $this->selected[$table] = [];
            }
        }

        if ($this->tableExists('product_variants') && $this->hasColumn('product_variants', 'product_id')) {
            $this->addForeignValuesFromSelected('product_variants', 'product_id', 'products');
            $productIds = $this->ids('products');
            if ($productIds !== []) {
                $this->addWhereIn('product_variants', 'product_id', $productIds);
            }
        }

        foreach (['warehouse_stocks', 'warehouse_location_stocks'] as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            // A stock row may itself be the changed seed. Promote its dimensions
            // before finding the rest of the current snapshot for those products.
            $this->addForeignValuesFromSelected($table, 'product_id', 'products');
            $this->addForeignValuesFromSelected($table, 'product_variant_id', 'product_variants');
            $this->addForeignValuesFromSelected($table, 'warehouse_id', 'warehouses');

            $query = DB::table($table);
            $hasScope = false;
            $query->where(function (Builder $scope) use ($table, &$hasScope): void {
                foreach ([
                    'product_id' => $this->ids('products'),
                    'product_variant_id' => $this->ids('product_variants'),
                ] as $column => $ids) {
                    if ($ids !== [] && $this->hasColumn($table, $column)) {
                        $scope->orWhereIn($column, $ids);
                        $hasScope = true;
                    }
                }
            });

            if ($hasScope) {
                $this->addQueryIds($table, $query);
                $this->addForeignValuesFromSelected($table, 'product_id', 'products');
                $this->addForeignValuesFromSelected($table, 'product_variant_id', 'product_variants');
                $this->addForeignValuesFromSelected($table, 'warehouse_id', 'warehouses');
            }
        }
    }

    private function addForeignValuesFromSelected(string $source, string $foreignKey, string $target): void
    {
        if (! $this->tableExists($source) || ! $this->tableExists($target) || ! $this->hasColumn($source, $foreignKey)) {
            return;
        }

        foreach ($this->selectedRows($source, [$foreignKey]) as $row) {
            $this->addIds($target, [$row->{$foreignKey} ?? null]);
        }
    }

    private function addWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! $this->tableExists($table) || ! $this->hasColumn($table, $column)) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->addQueryIds($table, DB::table($table)->whereIn($column, $chunk));
        }
    }

    private function addQueryIds(string $table, Builder $query): void
    {
        if (! $this->hasColumn($table, 'id')) {
            $this->warnings[] = "table {$table} has no id column and was skipped";

            return;
        }

        $query->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            $this->addIds($table, $rows->pluck('id')->all());
        }, 'id');
    }

    private function addIds(string $table, array $ids): void
    {
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $this->selected[$table][(int) $id] = true;
            }
        }
    }

    /** @return \Generator<int, object> */
    private function selectedRows(string $table, array $columns): \Generator
    {
        $ids = $this->ids($table);
        if ($ids === []) {
            return;
        }

        $columns = array_values(array_unique(array_merge(['id'], $columns)));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->get($columns) as $row) {
                yield $row;
            }
        }
    }

    /** @return array<int, int> */
    private function ids(string $table): array
    {
        return array_map('intval', array_keys($this->selected[$table] ?? []));
    }

    private function selectedCount(): int
    {
        return array_sum(array_map('count', $this->selected));
    }

    /** @return array<int, string> */
    private function exportableTables(): array
    {
        $ordered = array_merge(
            self::DELTA_TABLES,
            ['preinvoice_orders', 'preinvoice_order_items', 'preinvoice_order_reviews', 'preinvoice_draft_reservations'],
            array_keys(self::SNAPSHOT_TABLES),
        );

        return array_values(array_unique($ordered));
    }

    private function writeTableJson(string $table, array $ids, string $path): int
    {
        sort($ids, SORT_NUMERIC);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        $count = 0;
        fwrite($handle, "[\n");

        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = DB::table($table)->whereIn('id', $chunk)->orderBy('id')->get();
                $invoiceItems = $table === 'invoices' ? $this->invoiceItemSnapshots($rows->pluck('id')->all()) : [];

                foreach ($rows as $row) {
                    $data = $this->sanitizeRow($table, (array) $row);
                    if ($table === 'invoices') {
                        $items = $invoiceItems[(int) $row->id] ?? [];
                        $data['item_count'] = count($items);
                        $data['items_snapshot'] = $items;
                    }

                    $encoded = $this->encodeJson($data);
                    $encoded = preg_replace('/^/m', '  ', $encoded) ?? $encoded;
                    fwrite($handle, ($count > 0 ? ",\n" : '').$encoded);
                    $count++;
                }
            }

            fwrite($handle, "\n]\n");
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function invoiceItemSnapshots(array $invoiceIds): array
    {
        if ($invoiceIds === [] || ! $this->tableExists('invoice_items')) {
            return [];
        }

        $grouped = [];
        foreach (DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->orderBy('id')->get() as $row) {
            $grouped[(int) $row->invoice_id][] = $this->sanitizeRow('invoice_items', (array) $row);
        }

        return $grouped;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeRow(string $table, array $row): array
    {
        $types = $this->columnTypeMap($table);
        $clean = [];

        foreach ($row as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            $type = strtolower($types[(string) $key] ?? '');
            if ($value !== null && preg_match('/(?:^|\b)(?:tinyint|smallint|mediumint|int|integer|bigint|serial)(?:\b|$)/', $type)) {
                $value = (int) $value;
            } elseif ($value !== null && (str_contains($type, 'real') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'decimal'))) {
                $value = (float) $value;
            } elseif ($value !== null && str_contains($type, 'bool')) {
                $value = (bool) $value;
            } elseif (is_string($value) && (str_contains($type, 'json') || $this->looksLikeJson($value))) {
                try {
                    $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    // Keep malformed legacy JSON as its original string for forensic use.
                }
            }

            $clean[(string) $key] = $this->sanitizeNested($value);
        }

        return $clean;
    }

    private function sanitizeNested(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $nested) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }
            $clean[$key] = $this->sanitizeNested($nested);
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(?:^|_)(?:password|remember_token|otp|api_?key|secret|token|session)(?:_|$)/i', $key);
    }

    private function looksLikeJson(string $value): bool
    {
        $trimmed = ltrim($value);

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    /** @return array<int, string> */
    private function changeTimestampColumns(string $table): array
    {
        return array_values(array_intersect(self::CHANGE_TIMESTAMPS, $this->columns($table)));
    }

    /** @return array<int, string> */
    private function columns(string $table): array
    {
        if (isset($this->columnNames[$table])) {
            return $this->columnNames[$table];
        }

        if (! $this->tableExists($table)) {
            return $this->columnNames[$table] = [];
        }

        $metadata = Schema::getColumns($table);
        $this->columnNames[$table] = array_values(array_map(fn (array $column): string => (string) $column['name'], $metadata));
        $this->columnTypes[$table] = [];
        foreach ($metadata as $column) {
            $this->columnTypes[$table][(string) $column['name']] = (string) ($column['type_name'] ?? $column['type'] ?? '');
        }

        return $this->columnNames[$table];
    }

    /** @return array<string, string> */
    private function columnTypeMap(string $table): array
    {
        $this->columns($table);

        return $this->columnTypes[$table] ?? [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function tableExists(string $table): bool
    {
        static $exists = [];

        return $exists[$table] ??= Schema::hasTable($table);
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function createZip(string $directory, string $zipPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
        if ($result !== true) {
            throw new RuntimeException("Cannot create ZIP archive {$zipPath} (code {$result}).");
        }

        try {
            foreach ((new Filesystem)->files($directory) as $file) {
                if (! $zip->addFile($file->getPathname(), $file->getFilename())) {
                    throw new RuntimeException('Cannot add '.$file->getFilename().' to ZIP archive.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, int> $counts */
    private function printSummary(array $counts, string $zipPath): void
    {
        $this->line('Invoices changed/related: '.($counts['invoices'] ?? 0));
        $this->line('Invoice items exported: '.($counts['invoice_items'] ?? 0));
        $this->line('Customers: '.($counts['customers'] ?? 0));
        $this->line('Sales returns: '.($counts['sales_return_documents'] ?? 0));
        $this->line('Stock movements: '.($counts['stock_movements'] ?? 0));
        $this->line('Inbound receipts: '.($counts['warehouse_inbound_receipts'] ?? 0));
        $this->line('Snapshot warehouse stocks: '.($counts['warehouse_stocks'] ?? 0));
        $this->newLine();
        $this->line('Output:');
        $this->info($zipPath);
    }
}
