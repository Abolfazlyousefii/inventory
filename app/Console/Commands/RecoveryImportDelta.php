<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class RecoveryImportDelta extends Command {
    protected $signature = 'recovery:import-delta
        {file : Path to a ZIP created by recovery:export-delta}
        {--dry-run : Validate and compare without writing to the database}
        {--with-snapshots : Also merge snapshot-only tables}
        {--overwrite-newer : Allow source rows to overwrite newer destination rows}
        {--allow-partial : Commit valid rows even when other rows fail}
        {--force : Do not ask for confirmation in production}
        {--report= : JSON report path; defaults to storage/app/recovery-import-report.json}
        {--no-details : Do not print every row result to the console}
        {--stream-report : Stream row reports to disk to reduce memory usage}';

    protected $description = 'Import a RecoveryExportDelta ZIP and report every inserted, updated, unchanged, skipped, or failed row';

    private const CHANGE_TIMESTAMPS = [
        'updated_at',
        'created_at',
        'deleted_at',
        'cancelled_at',
        'applied_at',
        'received_at',
        'approved_at',
        'voided_at',
    ];

    /** Parent tables are attempted before their common dependants. */
    private const IMPORT_PRIORITY = [
        'customers',
        'customer_phones',
        'warehouses',
        'products',
        'product_variants',
        'commission_settings',
        'commission_campaigns',
        'commission_campaign_targets',
        'commission_targets',
        'commission_rate_revisions',
        'commission_periods',
        'preinvoice_orders',
        'preinvoice_order_items',
        'preinvoice_order_reviews',
        'preinvoice_draft_reservations',
        'invoices',
        'invoice_items',
        'invoice_payments',
        'cheques',
        'invoice_notes',
        'invoice_attachments',
        'invoice_edit_audits',
        'invoice_collection_revisions',
        'invoice_collection_revision_items',
        'sales_return_documents',
        'sales_return_document_items',
        'sales_return_document_revisions',
        'warehouse_transfers',
        'warehouse_transfer_items',
        'warehouse_inbound_receipts',
        'stock_movements',
        'warehouse_inbound_receipt_items',
        'warehouse_location_movements',
        'sales_havaleh_histories',
        'activity_logs',
        'seller_reassignment_audits',
        'finance_commission_batches',
        'finance_commission_batch_items',
        'seller_sales_documents',
        'seller_sales_document_items',
        'commission_documents',
        'commission_document_items',
        'commission_document_events',
        'commission_document_corrections',
        'commission_correction_entries',
        'commission_reconciliation_warnings',
        'commission_adjustments',
        'commission_document_adjustments',
        'commission_settlements',
        'commission_payments',
        'commission_period_events',
        'commission_ledger_entries',
        'commission_calculation_warnings',
        'warehouse_stocks',
        'warehouse_location_stocks',
    ];

    /** @var array<string, array<int, string>> */
    private array $columnNames = [];

    /** @var array<string, array<string, string>> */
    private array $columnTypes = [];

    /** @var array<string, bool> */
    private array $tableExists = [];

    /** @var array<string, mixed> */
    private array $report = [];

    private bool $transactionOpen = false;

    private ?string $streamReportFile = null;

    /** @var resource|null */
    private $streamReportHandle = null;

    public function handle( Filesystem $files ): int {
        $startedAt   = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $reportPath  = $this->reportPath();
        $archivePath = null;
        $exitCode    = self::FAILURE;

        $this->report = [
            'command'         => 'recovery:import-delta',
            'started_at'      => $startedAt->toIso8601String(),
            'finished_at'     => null,
            'status'          => 'initializing',
            'mode'            => [
                'dry_run'         => (bool) $this->option('dry-run'),
                'with_snapshots'  => (bool) $this->option('with-snapshots'),
                'overwrite_newer' => (bool) $this->option('overwrite-newer'),
                'allow_partial'   => (bool) $this->option('allow-partial'),
            ],
            'archive'         => [],
            'source_manifest' => null,
            'files'           => [],
            'tables'          => [],
            'transaction'     => [
                'started'     => false,
                'committed'   => false,
                'rolled_back' => false,
                'reason'      => null,
            ],
            'summary'         => [],
            'warnings'        => [],
            'errors'          => [],
        ];

        try {
            if ( true ) {
                $this->streamReportFile   = storage_path('app/recovery-import-rows.ndjson');
                $this->streamReportHandle = fopen($this->streamReportFile, 'wb');

                if ( $this->streamReportHandle === false ) {
                    throw new RuntimeException('Cannot create stream report file.');
                }
            }

            $archivePath             = $this->resolveArchivePath((string) $this->argument('file'));
            $this->report['archive'] = [
                'path'       => $archivePath,
                'name'       => basename($archivePath),
                'size_bytes' => filesize($archivePath) ? : 0,
                'sha256'     => hash_file('sha256', $archivePath),
            ];

            [ $manifest, $packages ] = $this->readArchive($archivePath);
            $this->report['source_manifest'] = $manifest;

            if ( !$this->option('dry-run')
                 && app()->environment('production')
                 && !$this->option('force')
                 && !$this->confirm('Import this recovery delta into the production database?') ) {
                throw new RuntimeException('Import cancelled by operator.');
            }

            $this->printHeader($manifest);
            $this->importPackages($packages);
            $this->finishReport();

            $failed = (int) ( $this->report['summary']['failed'] ?? 0 );
            if ( $failed > 0 ) {
                $this->report['status'] = $this->option('allow-partial')
                                          && ( $this->report['transaction']['committed'] ?? false ) ? 'partial' : 'failed';
                $exitCode               = self::FAILURE;
            }
            else {
                $this->report['status'] = $this->option('dry-run') ? 'dry_run_ok' : 'success';
                $exitCode               = self::SUCCESS;
            }
        } catch ( Throwable $exception ) {
            if ( $this->transactionOpen ) {
                DB::rollBack();
                $this->transactionOpen                      = false;
                $this->report['transaction']['rolled_back'] = true;
                $this->report['transaction']['reason']      = $exception->getMessage();
                $this->markSuccessfulWritesAsRolledBack();
            }

            $this->report['status']   = 'failed';
            $this->report['errors'][] = [
                'type'    => $exception::class,
                'message' => $exception->getMessage(),
            ];
            $this->error('Recovery import failed: ' . $exception->getMessage());
            $this->finishReport();
            $exitCode = self::FAILURE;
        } finally {
            $this->report['finished_at'] = CarbonImmutable::now((string) config('app.timezone', 'UTC'))
                ->toIso8601String();

            try {
                if ( $this->streamReportHandle ) {
                    fclose($this->streamReportHandle);
                    $this->streamReportHandle           = null;
                    $this->report['stream_report_file'] = $this->streamReportFile;
                }

                $this->saveReport($files, $reportPath);
                $this->printSummary();
                $this->newLine();
                $this->info('Report saved: ' . $reportPath);
            } catch ( Throwable $reportException ) {
                $this->error('Could not save import report: ' . $reportException->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function readArchive( string $archivePath ): array {
        if ( !class_exists(ZipArchive::class) ) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }

        $zip    = new ZipArchive();
        $result = $zip->open($archivePath, ZipArchive::RDONLY);
        if ( $result !== true ) {
            throw new RuntimeException("Cannot open ZIP archive (code {$result}).");
        }

        try {
            $manifestEntry = $this->findManifestEntry($zip);
            $manifestRaw   = $zip->getFromName($manifestEntry);
            if ( $manifestRaw === false ) {
                throw new RuntimeException('manifest.json could not be read from the ZIP.');
            }

            $manifest = $this->decodeJsonObject($manifestRaw, 'manifest.json');
            if ( ( $manifest['export_type'] ?? null ) !== 'recovery_delta' ) {
                throw new RuntimeException('The ZIP is not a recovery_delta export.');
            }

            $tableDetails      = $this->manifestTableDetails($manifest);
            $manifestDirectory = dirname(str_replace('\\', '/', $manifestEntry));
            $manifestDirectory = $manifestDirectory === '.' ? '' : trim($manifestDirectory, '/') . '/';
            $packages          = [];

            foreach ( $tableDetails as $table => $detail ) {
                if ( !preg_match('/^[A-Za-z0-9_]+$/', $table) ) {
                    throw new RuntimeException("Unsafe table name in manifest: {$table}");
                }

                $fileName = (string) ( $detail['file'] ?? ( $table . '.json' ) );
                if ( $fileName === '' || basename($fileName) !== $fileName || str_contains($fileName, '..') ) {
                    throw new RuntimeException("Unsafe data filename for table {$table}.");
                }

                $entry = $manifestDirectory . $fileName;
                $raw   = $zip->getFromName($entry);
                if ( $raw === false ) {
                    throw new RuntimeException("Data file {$fileName} for table {$table} is missing from the ZIP.");
                }

                $actualHash   = hash('sha256', $raw);
                $checksumMap  = is_array($manifest['file_sha256'] ?? null) ? $manifest['file_sha256'] : [];
                $expectedHash = $checksumMap[$fileName] ?? null;
                if ( is_string($expectedHash) && !hash_equals(strtolower($expectedHash), strtolower($actualHash)) ) {
                    throw new RuntimeException("Checksum mismatch for {$fileName}; import was blocked.");
                }
                if ( !is_string($expectedHash) ) {
                    $this->report['warnings'][] = "No SHA-256 checksum was provided for {$fileName}.";
                }

                $rows          = $this->decodeJsonList($raw, $fileName);
                $manifestCount = isset($detail['count']) ? (int) $detail['count'] : null;
                if ( $manifestCount !== null && $manifestCount !== count($rows) ) {
                    throw new RuntimeException("Row-count mismatch for {$fileName}: manifest={$manifestCount}, actual=" . count($rows) . '.');
                }

                $snapshotOnly = (bool) ( $detail['snapshot_only'] ?? in_array($table, (array) ( $manifest['snapshot_only_tables'] ?? [] ), true) );

                $this->report['files'][$fileName] = [
                    'table'           => $table,
                    'snapshot_only'   => $snapshotOnly,
                    'size_bytes'      => strlen($raw),
                    'manifest_rows'   => $manifestCount,
                    'decoded_rows'    => count($rows),
                    'expected_sha256' => $expectedHash,
                    'actual_sha256'   => $actualHash,
                    'checksum'        => is_string($expectedHash) ? 'verified' : 'not_provided',
                    'status'          => $snapshotOnly && !$this->option('with-snapshots') ? 'snapshot_skipped' : 'ready',
                ];

                $this->initializeTableReport($table, $fileName, $snapshotOnly, count($rows));
                $packages[$table] = [
                    'file'          => $fileName,
                    'snapshot_only' => $snapshotOnly,
                    'rows'          => $rows,
                ];
            }

            return [ $manifest, $packages ];
        } finally {
            $zip->close();
        }
    }

    private function findManifestEntry( ZipArchive $zip ): string {
        $candidates = [];

        for ( $index = 0; $index < $zip->numFiles; $index ++ ) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if ( str_starts_with($name, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $name) ) {
                throw new RuntimeException("Unsafe ZIP entry: {$name}");
            }
            if ( $name === 'manifest.json' ) {
                return $name;
            }
            if ( basename($name) === 'manifest.json' ) {
                $candidates[] = $name;
            }
        }

        if ( count($candidates) === 1 ) {
            return $candidates[0];
        }
        if ( $candidates === [] ) {
            throw new RuntimeException('manifest.json is missing from the ZIP.');
        }

        throw new RuntimeException('The ZIP contains more than one manifest.json.');
    }

    /** @return array<string, array<string, mixed>> */
    private function manifestTableDetails( array $manifest ): array {
        $details = $manifest['table_details'] ?? null;
        if ( is_array($details) && $details !== [] ) {
            $normalized = [];
            foreach ( $details as $table => $detail ) {
                $normalized[(string) $table] = is_array($detail) ? $detail : [];
            }

            return $normalized;
        }

        $tables = $manifest['tables'] ?? [];
        if ( !is_array($tables) || $tables === [] ) {
            throw new RuntimeException('Manifest contains neither table_details nor tables.');
        }

        $this->report['warnings'][] = 'Manifest has no table_details; filenames were inferred from table names.';
        $normalized                 = [];
        foreach ( $tables as $table ) {
            $table              = (string) $table;
            $normalized[$table] = [
                'file'          => $table . '.json',
                'count'         => isset($manifest['counts'][$table]) ? (int) $manifest['counts'][$table] : null,
                'snapshot_only' => in_array($table, (array) ( $manifest['snapshot_only_tables'] ?? [] ), true),
            ];
        }

        return $normalized;
    }

    /** @param array<string, array<string, mixed>> $packages */
    private function importPackages( array $packages ): void {
        $dryRun       = (bool) $this->option('dry-run');
        $allowPartial = (bool) $this->option('allow-partial');

        if ( !$dryRun ) {
            DB::beginTransaction();
            $this->transactionOpen                  = true;
            $this->report['transaction']['started'] = true;
        }

        try {
            $pending       = $this->buildPendingRows($packages);
            $maximumPasses = max(2, count($packages) + 2);

            for ( $pass = 1; $pass <= $maximumPasses && $pending !== []; $pass ++ ) {
                $next              = [];
                $completedThisPass = 0;

                foreach ( $pending as $item ) {
                    try {
                        $result = $dryRun ? $this->applyRow($item['table'], $item['row'], true) : DB::transaction(fn(): array => $this->applyRow($item['table'], $item['row'], false), 1);

                        $result['source_index'] = $item['source_index'];
                        $result['attempt_pass'] = $pass;
                        $this->recordRow($item['table'], $result);
                        $completedThisPass ++;
                    } catch ( Throwable $exception ) {
                        $item['last_error'] = [
                            'type'    => $exception::class,
                            'message' => $exception->getMessage(),
                        ];
                        $next[]             = $item;
                    }
                }

                $pending = $next;
                if ( $completedThisPass === 0 ) {
                    break;
                }
            }

            foreach ( $pending as $item ) {
                $this->recordRow($item['table'], [
                    'id'           => $this->rowId($item['row']),
                    'status'       => 'failed',
                    'reason'       => 'database_write_failed',
                    'error'        => $item['last_error'] ?? [ 'message' => 'Row could not be imported.' ],
                    'source_index' => $item['source_index'],
                ]);
            }

            $this->finishReport();
            $failed = (int) ( $this->report['summary']['failed'] ?? 0 );

            if ( !$dryRun ) {
                if ( $failed > 0 && !$allowPartial ) {
                    DB::rollBack();
                    $this->transactionOpen                      = false;
                    $this->report['transaction']['rolled_back'] = true;
                    $this->report['transaction']['reason']      = 'One or more rows failed and --allow-partial was not supplied.';
                    $this->markSuccessfulWritesAsRolledBack();
                }
                else {
                    DB::commit();
                    $this->transactionOpen                    = false;
                    $this->report['transaction']['committed'] = true;
                }
            }
        } catch ( Throwable $exception ) {
            if ( $this->transactionOpen ) {
                DB::rollBack();
                $this->transactionOpen                      = false;
                $this->report['transaction']['rolled_back'] = true;
                $this->report['transaction']['reason']      = $exception->getMessage();
                $this->markSuccessfulWritesAsRolledBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     * @return array<int, array{table: string, row: array<string, mixed>, source_index: int, last_error?: array<string, string>}>
     */
    private function buildPendingRows( array $packages ): array {
        $pending          = [];
        $orderedTables    = array_keys($packages);
        $priority         = array_flip(self::IMPORT_PRIORITY);
        $manifestPosition = array_flip($orderedTables);

        usort($orderedTables, static function ( string $left, string $right ) use ( $priority, $manifestPosition ): int {
            $leftRank  = $priority[$left] ?? ( 10000 + ( $manifestPosition[$left] ?? 0 ) );
            $rightRank = $priority[$right] ?? ( 10000 + ( $manifestPosition[$right] ?? 0 ) );

            return $leftRank <=> $rightRank;
        });

        foreach ( $orderedTables as $table ) {
            $package = $packages[$table];
            $rows    = $package['rows'];

            if ( $package['snapshot_only'] && !$this->option('with-snapshots') ) {
                foreach ( $rows as $index => $row ) {
                    $this->recordRow($table, [
                        'id'           => is_array($row) ? $this->rowId($row) : null,
                        'status'       => 'skipped',
                        'reason'       => 'snapshot_option_disabled',
                        'source_index' => $index,
                    ]);
                }
                $this->report['tables'][$table]['status'] = 'snapshot_skipped';
                continue;
            }

            if ( !$this->tableExists($table) ) {
                foreach ( $rows as $index => $row ) {
                    $this->recordRow($table, [
                        'id'           => is_array($row) ? $this->rowId($row) : null,
                        'status'       => 'skipped',
                        'reason'       => 'destination_table_missing',
                        'source_index' => $index,
                    ]);
                }
                $this->report['tables'][$table]['status'] = 'destination_table_missing';
                continue;
            }

            if ( !$this->hasColumn($table, 'id') ) {
                foreach ( $rows as $index => $row ) {
                    $this->recordRow($table, [
                        'id'           => is_array($row) ? $this->rowId($row) : null,
                        'status'       => 'skipped',
                        'reason'       => 'destination_id_column_missing',
                        'source_index' => $index,
                    ]);
                }
                $this->report['tables'][$table]['status'] = 'destination_id_column_missing';
                continue;
            }

            $seenIds = [];
            foreach ( $rows as $index => $row ) {
                if ( !is_array($row) || $this->isList($row) ) {
                    $this->recordRow($table, [
                        'id'           => null,
                        'status'       => 'failed',
                        'reason'       => 'row_is_not_a_json_object',
                        'source_index' => $index,
                    ]);
                    continue;
                }

                $id = $this->rowId($row);
                if ( $id === null ) {
                    $this->recordRow($table, [
                        'id'           => null,
                        'status'       => 'failed',
                        'reason'       => 'missing_or_invalid_positive_id',
                        'source_index' => $index,
                    ]);
                    continue;
                }

                if ( isset($seenIds[$id]) ) {
                    $this->recordRow($table, [
                        'id'                 => $id,
                        'status'             => 'failed',
                        'reason'             => 'duplicate_id_in_source_file',
                        'source_index'       => $index,
                        'first_source_index' => $seenIds[$id],
                    ]);
                    continue;
                }

                $seenIds[$id] = $index;
                $pending[]    = [
                    'table'        => $table,
                    'row'          => $row,
                    'source_index' => $index,
                ];
            }
        }

        return $pending;
    }

    /** @param array<string, mixed> $sourceRow
     * @return array<string, mixed>
     */
    private function applyRow( string $table, array $sourceRow, bool $dryRun ): array {
        $id = $this->rowId($sourceRow);
        if ( $id === null ) {
            throw new RuntimeException("Invalid id in {$table} row.");
        }

        [ $data, $ignoredColumns ] = $this->databaseRow($table, $sourceRow);
        $data['id'] = $id;
        $this->mergeIgnoredColumns($table, $ignoredColumns);

        $existingQuery = DB::table($table)
            ->where('id', $id);
        if ( !$dryRun ) {
            $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->first();
        if ( $existing === null ) {
            if ( $dryRun ) {
                return [
                    'id'                     => $id,
                    'status'                 => 'would_insert',
                    'columns'                => array_keys($data),
                    'source_values'          => $data,
                    'ignored_source_columns' => $ignoredColumns,
                    'verified'               => null,
                ];
            }

            DB::table($table)
                ->insert($data);
            $this->verifyStoredRow($table, $id, $data);

            return [
                'id'                     => $id,
                'status'                 => 'inserted',
                'columns'                => array_keys($data),
                'source_values'          => $data,
                'ignored_source_columns' => $ignoredColumns,
                'verified'               => true,
            ];
        }

        $existingArray = (array) $existing;
        $changes       = [];
        $changeDetails = [];
        foreach ( $data as $column => $value ) {
            if ( $column === 'id' ) {
                continue;
            }

            $type = $this->columnTypes[$table][$column] ?? '';
            if ( !$this->valuesEqual($value, $existingArray[$column] ?? null, $type) ) {
                $changes[$column]       = $value;
                $changeDetails[$column] = [
                    'before' => $existingArray[$column] ?? null,
                    'after'  => $value,
                ];
            }
        }

        if ( $changes === [] ) {
            return [
                'id'                     => $id,
                'status'                 => 'unchanged',
                'columns_compared'       => array_keys($data),
                'source_values'          => $data,
                'ignored_source_columns' => $ignoredColumns,
                'verified'               => true,
            ];
        }

        $newer = $this->destinationNewer($data, $existingArray);
        if ( $newer !== null && !$this->option('overwrite-newer') ) {
            return [
                'id'                     => $id,
                'status'                 => 'skipped',
                'reason'                 => 'destination_row_is_newer',
                'changed_columns'        => array_keys($changes),
                'changes'                => $changeDetails,
                'timestamp_column'       => $newer['column'],
                'source_timestamp'       => $newer['source'],
                'destination_timestamp'  => $newer['destination'],
                'ignored_source_columns' => $ignoredColumns,
                'verified'               => null,
            ];
        }

        if ( $dryRun ) {
            return [
                'id'                     => $id,
                'status'                 => 'would_update',
                'changed_columns'        => array_keys($changes),
                'changes'                => $changeDetails,
                'ignored_source_columns' => $ignoredColumns,
                'verified'               => null,
            ];
        }

        DB::table($table)
            ->where('id', $id)
            ->update($changes);
        $this->verifyStoredRow($table, $id, $data);

        return [
            'id'                     => $id,
            'status'                 => 'updated',
            'changed_columns'        => array_keys($changes),
            'changes'                => $changeDetails,
            'ignored_source_columns' => $ignoredColumns,
            'verified'               => true,
        ];
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function databaseRow( string $table, array $sourceRow ): array {
        $columns = array_flip($this->columns($table));
        $data    = [];
        $ignored = [];

        foreach ( $sourceRow as $column => $value ) {
            $column = (string) $column;
            if ( !isset($columns[$column]) ) {
                $ignored[] = $column;
                continue;
            }

            $data[$column] = $this->databaseValue($value);
        }

        sort($ignored);

        return [ $data, $ignored ];
    }

    private function databaseValue( mixed $value ): mixed {
        if ( is_array($value) || is_object($value) ) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        if ( is_bool($value) ) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /** @param array<string, mixed> $expected */
    private function verifyStoredRow( string $table, int $id, array $expected ): void {
        $stored = DB::table($table)
            ->where('id', $id)
            ->first(array_keys($expected));
        if ( $stored === null ) {
            throw new RuntimeException("Verification failed: {$table} id={$id} does not exist after write.");
        }

        $stored = (array) $stored;
        foreach ( $expected as $column => $value ) {
            $type = $this->columnTypes[$table][$column] ?? '';
            if ( !$this->valuesEqual($value, $stored[$column] ?? null, $type) ) {
                throw new RuntimeException("Verification failed: {$table} id={$id}, column={$column}.");
            }
        }
    }

    private function valuesEqual( mixed $left, mixed $right, string $type ): bool {
        if ( $left === null || $right === null ) {
            return $left === null && $right === null;
        }

        $type = strtolower($type);
        if ( str_contains($type, 'json') || $this->looksLikeJson($left) || $this->looksLikeJson($right) ) {
            return $this->canonicalJson($left) === $this->canonicalJson($right);
        }

        if ( preg_match('/(?:tinyint|smallint|mediumint|bigint|integer|\bint\b|serial|bool)/', $type) ) {
            return (int) $left === (int) $right;
        }

        if ( preg_match('/(?:decimal|numeric|real|float|double)/', $type) ) {
            return (string) (float) $left === (string) (float) $right;
        }

        return (string) $left === (string) $right;
    }

    private function looksLikeJson( mixed $value ): bool {
        if ( is_array($value) || is_object($value) ) {
            return true;
        }
        if ( !is_string($value) ) {
            return false;
        }

        $trimmed = ltrim($value);

        return $trimmed !== '' && ( $trimmed[0] === '{' || $trimmed[0] === '[' );
    }

    private function canonicalJson( mixed $value ): ?string {
        try {
            if ( is_string($value) ) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            elseif ( is_object($value) ) {
                $value = (array) $value;
            }

            $value = $this->sortJsonValue($value);

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch ( Throwable ) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    private function sortJsonValue( mixed $value ): mixed {
        if ( !is_array($value) ) {
            return $value;
        }

        foreach ( $value as $key => $nested ) {
            $value[$key] = $this->sortJsonValue($nested);
        }

        if ( !$this->isList($value) ) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $destination
     * @return array{column: string, source: mixed, destination: mixed}|null
     */
    private function destinationNewer( array $source, array $destination ): ?array {
        foreach ( self::CHANGE_TIMESTAMPS as $column ) {
            if ( !array_key_exists($column, $source)
                 || !array_key_exists($column, $destination)
                 || $source[$column] === null
                 || $destination[$column] === null ) {
                continue;
            }

            $sourceTime      = strtotime((string) $source[$column]);
            $destinationTime = strtotime((string) $destination[$column]);
            $isNewer         = $sourceTime !== false && $destinationTime !== false ? $destinationTime > $sourceTime : strcmp((string) $destination[$column], (string) $source[$column]) > 0;

            if ( $isNewer ) {
                return [
                    'column'      => $column,
                    'source'      => $source[$column],
                    'destination' => $destination[$column],
                ];
            }

            // updated_at is authoritative when both rows have it.
            if ( $column === 'updated_at' ) {
                return null;
            }
        }

        return null;
    }

    private function initializeTableReport( string $table, string $file, bool $snapshotOnly, int $sourceRows ): void {
        $this->report['tables'][$table] = [
            'file'                   => $file,
            'snapshot_only'          => $snapshotOnly,
            'status'                 => $sourceRows === 0 ? 'no_source_rows' : 'pending',
            'source_rows'            => $sourceRows,
            'inserted'               => 0,
            'updated'                => 0,
            'unchanged'              => 0,
            'skipped'                => 0,
            'failed'                 => 0,
            'would_insert'           => 0,
            'would_update'           => 0,
            'rolled_back_insert'     => 0,
            'rolled_back_update'     => 0,
            'verified_writes'        => 0,
            'ignored_source_columns' => [],
            'rows'                   => [],
        ];
    }

    /** @param array<string, mixed> $result */
    private function recordRow( string $table, array $result ): void {
        $status = (string) ( $result['status'] ?? 'failed' );
        if ( !array_key_exists($status, $this->report['tables'][$table]) ) {
            $this->report['tables'][$table][$status] = 0;
        }
        $this->report['tables'][$table][$status] ++;

        if ( ( $result['verified'] ?? false ) === true && in_array($status, [ 'inserted', 'updated' ], true) ) {
            $this->report['tables'][$table]['verified_writes'] ++;
        }

        if ( $this->streamReportHandle ) {
            fwrite($this->streamReportHandle, json_encode([
                        'table' => $table,
                        'row'   => $result,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        }

        if ( $this->report['tables'][$table]['status'] === 'pending' ) {
            $this->report['tables'][$table]['status'] = 'processed';
        }
    }

    /** @param array<int, string> $columns */
    private function mergeIgnoredColumns( string $table, array $columns ): void {
        $merged = array_values(array_unique(array_merge($this->report['tables'][$table]['ignored_source_columns'], $columns)));
        sort($merged);
        $this->report['tables'][$table]['ignored_source_columns'] = $merged;
    }

    private function markSuccessfulWritesAsRolledBack(): void {
        foreach ( $this->report['tables'] as &$tableReport ) {
            foreach ( $tableReport['rows'] as &$row ) {
                if ( ( $row['status'] ?? null ) === 'inserted' ) {
                    $row['status']   = 'rolled_back_insert';
                    $row['verified'] = false;
                    $row['reason']   = 'transaction_rolled_back';
                    $tableReport['inserted'] --;
                    $tableReport['rolled_back_insert'] ++;
                }
                elseif ( ( $row['status'] ?? null ) === 'updated' ) {
                    $row['status']   = 'rolled_back_update';
                    $row['verified'] = false;
                    $row['reason']   = 'transaction_rolled_back';
                    $tableReport['updated'] --;
                    $tableReport['rolled_back_update'] ++;
                }
            }
            unset($row);
            $tableReport['verified_writes'] = 0;
        }
        unset($tableReport);

        $this->finishReport();
    }

    private function finishReport(): void {
        $summary = [
            'files'              => count($this->report['files']),
            'tables'             => count($this->report['tables']),
            'source_rows'        => 0,
            'inserted'           => 0,
            'updated'            => 0,
            'unchanged'          => 0,
            'skipped'            => 0,
            'failed'             => 0,
            'would_insert'       => 0,
            'would_update'       => 0,
            'rolled_back_insert' => 0,
            'rolled_back_update' => 0,
            'verified_writes'    => 0,
        ];

        foreach ( $this->report['tables'] as $tableReport ) {
            foreach ( array_keys($summary) as $key ) {
                if ( in_array($key, [ 'files', 'tables' ], true) ) {
                    continue;
                }
                $summary[$key] += (int) ( $tableReport[$key] ?? 0 );
            }
        }

        $summary['database_changes_committed'] = ( $this->report['transaction']['committed'] ?? false ) ? $summary['inserted'] + $summary['updated'] : 0;
        $this->report['summary']               = $summary;

        foreach ( $this->report['tables'] as $table => $tableReport ) {
            $file = $tableReport['file'];
            if ( !isset($this->report['files'][$file]) ) {
                continue;
            }

            $this->report['files'][$file]['database_result'] = [
                'table'        => $table,
                'inserted'     => (int) $tableReport['inserted'],
                'updated'      => (int) $tableReport['updated'],
                'unchanged'    => (int) $tableReport['unchanged'],
                'skipped'      => (int) $tableReport['skipped'],
                'failed'       => (int) $tableReport['failed'],
                'would_insert' => (int) $tableReport['would_insert'],
                'would_update' => (int) $tableReport['would_update'],
                'rolled_back'  => (int) $tableReport['rolled_back_insert'] + (int) $tableReport['rolled_back_update'],
            ];
        }
    }

    /** @param array<string, mixed> $manifest */
    private function printHeader( array $manifest ): void {
        $this->newLine();
        $this->line('====================================');
        $this->line(' RECOVERY IMPORT REPORT');
        $this->line('====================================');
        $this->line('Archive: ' . ( $this->report['archive']['name'] ?? 'unknown' ));
        $this->line('Source label: ' . ( $manifest['source_label'] ?? 'unknown' ));
        $this->line('Generated at: ' . ( $manifest['generated_at'] ?? $manifest['export_generated_at'] ?? 'unknown' ));
        $this->line('Mode: ' . ( $this->option('dry-run') ? 'DRY RUN' : 'WRITE' ));
        $this->newLine();
    }

    private function printSummary(): void {
        if ( $this->report['tables'] !== [] ) {
            $rows = [];
            foreach ( $this->report['tables'] as $table => $detail ) {
                $rows[] = [
                    $table,
                    $detail['file'],
                    $detail['source_rows'],
                    $detail['inserted'],
                    $detail['updated'],
                    $detail['unchanged'],
                    $detail['skipped'],
                    $detail['failed'],
                    $detail['would_insert'],
                    $detail['would_update'],
                    $detail['rolled_back_insert'] + $detail['rolled_back_update'],
                ];
            }

            $this->table([
                    'Table',
                    'File',
                    'Rows',
                    'Inserted',
                    'Updated',
                    'Same',
                    'Skipped',
                    'Failed',
                    'Would Insert',
                    'Would Update',
                    'Rolled Back',
                ], $rows,);
        }

        if ( !$this->option('no-details') ) {
            foreach ( $this->report['tables'] as $table => $detail ) {
                if ( $detail['rows'] === [] ) {
                    continue;
                }

                $this->newLine();
                $this->line("<info>{$table}</info> ({$detail['file']})");
                foreach ( $detail['rows'] as $row ) {
                    $id      = $row['id'] ?? 'n/a';
                    $status  = strtoupper((string) ( $row['status'] ?? 'unknown' ));
                    $message = "  [{$status}] id={$id}";
                    if ( !empty($row['changed_columns']) ) {
                        $message .= ' changed=' . implode(',', $row['changed_columns']);
                    }
                    elseif ( !empty($row['columns']) ) {
                        $message .= ' columns=' . implode(',', $row['columns']);
                    }
                    if ( !empty($row['reason']) ) {
                        $message .= ' reason=' . $row['reason'];
                    }
                    if ( !empty($row['error']['message']) ) {
                        $message .= ' error=' . $row['error']['message'];
                    }
                    $this->line($message);
                }
            }
        }

        $summary = $this->report['summary'];
        $this->newLine();
        $this->line('------------------------------------');
        $this->line('Status: ' . strtoupper((string) $this->report['status']));
        $this->line('Source rows: ' . ( $summary['source_rows'] ?? 0 ));
        $this->line('Inserted: ' . ( $summary['inserted'] ?? 0 ));
        $this->line('Updated: ' . ( $summary['updated'] ?? 0 ));
        $this->line('Unchanged: ' . ( $summary['unchanged'] ?? 0 ));
        $this->line('Skipped: ' . ( $summary['skipped'] ?? 0 ));
        $this->line('Failed: ' . ( $summary['failed'] ?? 0 ));
        if ( $this->option('dry-run') ) {
            $this->line('Would insert: ' . ( $summary['would_insert'] ?? 0 ));
            $this->line('Would update: ' . ( $summary['would_update'] ?? 0 ));
        }
        $this->line('Committed database changes: ' . ( $summary['database_changes_committed'] ?? 0 ));
    }

    private function saveReport( Filesystem $files, string $path ): void {
        $directory = dirname($path);
        if ( !$files->isDirectory($directory) && !$files->makeDirectory($directory, 0750, true) ) {
            throw new RuntimeException("Cannot create report directory {$directory}.");
        }

        $json = json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if ( $files->put($path, $json . PHP_EOL) === false ) {
            throw new RuntimeException("Cannot write report {$path}.");
        }
    }

    private function reportPath(): string {
        $option = trim((string) $this->option('report'));
        if ( $option === '' ) {
            return storage_path('app/recovery-import-report.json');
        }

        return $this->absolutePath($option);
    }

    private function resolveArchivePath( string $path ): string {
        $absolute = $this->absolutePath(trim($path));
        $real     = realpath($absolute);

        if ( $real === false || !is_file($real) || !is_readable($real) ) {
            throw new RuntimeException("Recovery ZIP is not readable: {$absolute}");
        }

        return $real;
    }

    private function absolutePath( string $path ): string {
        if ( $path === '' ) {
            return storage_path();
        }
        if ( preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) ) {
            return $path;
        }

        return storage_path(trim($path, '\\/'));
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject( string $json, string $name ): array {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch ( JsonException $exception ) {
            throw new RuntimeException("Invalid JSON in {$name}: {$exception->getMessage()}", 0, $exception);
        }

        if ( !is_array($decoded) || $this->isList($decoded) ) {
            throw new RuntimeException("{$name} must contain a JSON object.");
        }

        return $decoded;
    }

    /** @return array<int, mixed> */
    private function decodeJsonList( string $json, string $name ): array {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch ( JsonException $exception ) {
            throw new RuntimeException("Invalid JSON in {$name}: {$exception->getMessage()}", 0, $exception);
        }

        if ( !is_array($decoded) || !$this->isList($decoded) ) {
            throw new RuntimeException("{$name} must contain a JSON array.");
        }

        return $decoded;
    }

    private function isList( array $value ): bool {
        if ( function_exists('array_is_list') ) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string, mixed> $row */
    private function rowId( array $row ): ?int {
        $id = $row['id'] ?? null;
        if ( !is_int($id) && !( is_string($id) && ctype_digit($id) ) ) {
            return null;
        }

        $id = (int) $id;

        return $id > 0 ? $id : null;
    }

    /** @return array<int, string> */
    private function columns( string $table ): array {
        if ( isset($this->columnNames[$table]) ) {
            return $this->columnNames[$table];
        }

        if ( !$this->tableExists($table) ) {
            return $this->columnNames[$table] = [];
        }

        $metadata                  = Schema::getColumns($table);
        $this->columnNames[$table] = [];
        $this->columnTypes[$table] = [];

        foreach ( $metadata as $column ) {
            $name                             = (string) $column['name'];
            $this->columnNames[$table][]      = $name;
            $this->columnTypes[$table][$name] = (string) ( $column['type_name'] ?? $column['type'] ?? '' );
        }

        return $this->columnNames[$table];
    }

    private function hasColumn( string $table, string $column ): bool {
        return in_array($column, $this->columns($table), true);
    }

    private function tableExists( string $table ): bool {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }
}
