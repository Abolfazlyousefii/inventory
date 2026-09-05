<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class RecoveryInspectDelta extends Command
{
    protected $signature = 'recovery:inspect-delta
        {file : Path to a ZIP created by recovery:export-delta}
        {--invoice-id=* : Source invoice numeric ID to inspect; may be repeated}
        {--preinvoice-id=* : Source preinvoice numeric ID to inspect; may be repeated}
        {--report= : JSON output path; defaults to storage/app/recovery-inspection-report.json}';

    protected $description = 'Inspect selected invoice and preinvoice records inside a recovery delta ZIP without database access';

    public function handle(Filesystem $files): int
    {
        try {
            $invoiceIds = $this->positiveIds((array) $this->option('invoice-id'));
            $preinvoiceIds = $this->positiveIds((array) $this->option('preinvoice-id'));

            if ($invoiceIds === [] && $preinvoiceIds === []) {
                throw new RuntimeException('Provide at least one --invoice-id or --preinvoice-id.');
            }

            $archivePath = $this->resolveArchivePath((string) $this->argument('file'));
            [$manifest, $tableRows] = $this->readNeededTables($archivePath, [
                'invoices',
                'invoice_items',
                'preinvoice_orders',
                'preinvoice_order_items',
            ]);

            $report = [
                'command' => 'recovery:inspect-delta',
                'database_access' => false,
                'archive' => [
                    'path' => $archivePath,
                    'name' => basename($archivePath),
                    'size_bytes' => filesize($archivePath) ?: 0,
                    'sha256' => hash_file('sha256', $archivePath),
                ],
                'source_manifest' => [
                    'project' => $manifest['project'] ?? null,
                    'source_label' => $manifest['source_label'] ?? null,
                    'generated_at' => $manifest['generated_at'] ?? $manifest['export_generated_at'] ?? null,
                    'hostname' => $manifest['hostname'] ?? null,
                    'app_env' => $manifest['app_env'] ?? $manifest['APP_ENV'] ?? null,
                    'cutoff' => $manifest['cutoff'] ?? null,
                ],
                'invoices' => [],
                'preinvoices' => [],
            ];

            foreach ($invoiceIds as $sourceId) {
                $parent = $this->findById($tableRows['invoices'] ?? [], $sourceId);
                $children = $this->childrenByForeignKey(
                    $tableRows['invoice_items'] ?? [],
                    'invoice_id',
                    $sourceId
                );

                $report['invoices'][] = [
                    'source_numeric_id' => $sourceId,
                    'source_parent_found' => $parent !== null,
                    'source_parent' => $parent,
                    'source_child_summary' => $this->childSummary($children),
                    'source_children' => $children,
                ];
            }

            foreach ($preinvoiceIds as $sourceId) {
                $parent = $this->findById($tableRows['preinvoice_orders'] ?? [], $sourceId);
                $children = $this->childrenByForeignKey(
                    $tableRows['preinvoice_order_items'] ?? [],
                    'preinvoice_order_id',
                    $sourceId
                );

                $report['preinvoices'][] = [
                    'source_numeric_id' => $sourceId,
                    'source_parent_found' => $parent !== null,
                    'source_parent' => $parent,
                    'source_child_summary' => $this->childSummary($children),
                    'source_children' => $children,
                ];
            }

            $path = $this->reportPath();
            $directory = dirname($path);
            if (!$files->isDirectory($directory) && !$files->makeDirectory($directory, 0750, true)) {
                throw new RuntimeException("Cannot create report directory {$directory}.");
            }

            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );

            if ($files->put($path, $json . PHP_EOL) === false) {
                throw new RuntimeException("Cannot write inspection report {$path}.");
            }

            $this->printSummary($report);
            $this->newLine();
            $this->info('Inspection report saved: ' . $path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Recovery inspection failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $neededTables
     * @return array{0: array<string, mixed>, 1: array<string, array<int, array<string, mixed>>>}
     */
    private function readNeededTables(string $archivePath, array $neededTables): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }

        $zip = new ZipArchive();
        $result = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($result !== true) {
            throw new RuntimeException("Cannot open ZIP archive (code {$result}).");
        }

        try {
            $manifestEntry = $this->findManifestEntry($zip);
            $manifestRaw = $zip->getFromName($manifestEntry);
            if ($manifestRaw === false) {
                throw new RuntimeException('manifest.json could not be read from the ZIP.');
            }

            $manifest = $this->decodeJsonObject($manifestRaw, 'manifest.json');
            if (($manifest['export_type'] ?? null) !== 'recovery_delta') {
                throw new RuntimeException('The ZIP is not a recovery_delta export.');
            }

            $details = $this->manifestTableDetails($manifest);
            $manifestDirectory = dirname(str_replace('\\', '/', $manifestEntry));
            $manifestDirectory = $manifestDirectory === '.' ? '' : trim($manifestDirectory, '/') . '/';
            $rowsByTable = [];

            foreach ($neededTables as $table) {
                if (!isset($details[$table])) {
                    $rowsByTable[$table] = [];
                    continue;
                }

                $fileName = (string) ($details[$table]['file'] ?? ($table . '.json'));
                if ($fileName === '' || basename($fileName) !== $fileName || str_contains($fileName, '..')) {
                    throw new RuntimeException("Unsafe data filename for table {$table}.");
                }

                $entry = $manifestDirectory . $fileName;
                $raw = $zip->getFromName($entry);
                if ($raw === false) {
                    throw new RuntimeException("Data file {$fileName} for table {$table} is missing from the ZIP.");
                }

                $this->verifyChecksum($manifest, $fileName, $raw);
                $rowsByTable[$table] = $this->decodeJsonList($raw, $fileName);
            }

            return [$manifest, $rowsByTable];
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $manifest */
    private function verifyChecksum(array $manifest, string $fileName, string $raw): void
    {
        $checksumMap = is_array($manifest['file_sha256'] ?? null)
            ? $manifest['file_sha256']
            : [];

        $expected = $checksumMap[$fileName] ?? null;
        if (!is_string($expected) || $expected === '') {
            return;
        }

        $actual = hash('sha256', $raw);
        if (!hash_equals(strtolower($expected), strtolower($actual))) {
            throw new RuntimeException("Checksum mismatch for {$fileName}; inspection was blocked.");
        }
    }

    private function findManifestEntry(ZipArchive $zip): string
    {
        $candidates = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

            if (str_starts_with($name, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $name)) {
                throw new RuntimeException("Unsafe ZIP entry: {$name}");
            }

            if ($name === 'manifest.json') {
                return $name;
            }

            if (basename($name) === 'manifest.json') {
                $candidates[] = $name;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates === []) {
            throw new RuntimeException('manifest.json is missing from the ZIP.');
        }

        throw new RuntimeException('The ZIP contains more than one manifest.json.');
    }

    /** @return array<string, array<string, mixed>> */
    private function manifestTableDetails(array $manifest): array
    {
        $details = $manifest['table_details'] ?? null;
        if (is_array($details) && $details !== []) {
            $normalized = [];
            foreach ($details as $table => $detail) {
                $normalized[(string) $table] = is_array($detail) ? $detail : [];
            }

            return $normalized;
        }

        $tables = $manifest['tables'] ?? [];
        if (!is_array($tables)) {
            throw new RuntimeException('Manifest contains neither valid table_details nor tables.');
        }

        $normalized = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            $normalized[$table] = [
                'file' => $table . '.json',
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if (isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function childrenByForeignKey(array $rows, string $foreignKey, int $parentId): array
    {
        $children = [];

        foreach ($rows as $row) {
            if (
                isset($row[$foreignKey])
                && is_numeric($row[$foreignKey])
                && (int) $row[$foreignKey] === $parentId
            ) {
                $children[] = $row;
            }
        }

        usort($children, static function (array $left, array $right): int {
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $children;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array<string, int|float|null>
     */
    private function childSummary(array $children): array
    {
        $quantity = 0.0;
        $lineTotal = 0.0;
        $lineDiscount = 0.0;
        $minId = null;
        $maxId = null;

        foreach ($children as $child) {
            if (isset($child['quantity']) && is_numeric($child['quantity'])) {
                $quantity += (float) $child['quantity'];
            }

            if (isset($child['line_total']) && is_numeric($child['line_total'])) {
                $lineTotal += (float) $child['line_total'];
            }

            if (isset($child['line_discount_amount']) && is_numeric($child['line_discount_amount'])) {
                $lineDiscount += (float) $child['line_discount_amount'];
            }

            if (isset($child['id']) && is_numeric($child['id'])) {
                $id = (int) $child['id'];
                $minId = $minId === null ? $id : min($minId, $id);
                $maxId = $maxId === null ? $id : max($maxId, $id);
            }
        }

        return [
            'count' => count($children),
            'sum_quantity' => $this->normalizeNumber($quantity),
            'sum_line_total' => $this->normalizeNumber($lineTotal),
            'sum_line_discount_amount' => $this->normalizeNumber($lineDiscount),
            'min_child_id' => $minId,
            'max_child_id' => $maxId,
        ];
    }

    private function normalizeNumber(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    /** @return array<int, int> */
    private function positiveIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $json, string $name): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$name}: {$exception->getMessage()}", 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("{$name} must contain a JSON object.");
        }

        return $decoded;
    }

    /** @return array<int, array<string, mixed>> */
    private function decodeJsonList(string $json, string $name): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$name}: {$exception->getMessage()}", 0, $exception);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException("{$name} must contain a JSON array.");
        }

        foreach ($decoded as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new RuntimeException("{$name} contains a non-object row at index {$index}.");
            }
        }

        return $decoded;
    }

    /** @param array<string, mixed> $report */
    private function printSummary(array $report): void
    {
        $this->newLine();
        $this->line('====================================');
        $this->line(' RECOVERY ZIP INSPECTION');
        $this->line('====================================');
        $this->line('Database access: NONE');
        $this->line('Archive: ' . ($report['archive']['name'] ?? 'unknown'));
        $this->line('Source: ' . ($report['source_manifest']['source_label'] ?? 'unknown'));
        $this->newLine();

        foreach ($report['invoices'] as $entry) {
            $parent = $entry['source_parent'];
            $summary = $entry['source_child_summary'];
            $this->line(sprintf(
                'Invoice source id=%d uuid=%s customer=%s mobile=%s items=%d total=%s',
                $entry['source_numeric_id'],
                $parent['uuid'] ?? 'NOT_FOUND',
                $parent['customer_name'] ?? 'n/a',
                $parent['customer_mobile'] ?? 'n/a',
                $summary['count'],
                (string) $summary['sum_line_total']
            ));
        }

        foreach ($report['preinvoices'] as $entry) {
            $parent = $entry['source_parent'];
            $summary = $entry['source_child_summary'];
            $this->line(sprintf(
                'Preinvoice source id=%d uuid=%s customer=%s mobile=%s items=%d total=%s',
                $entry['source_numeric_id'],
                $parent['uuid'] ?? 'NOT_FOUND',
                $parent['customer_name'] ?? 'n/a',
                $parent['customer_mobile'] ?? 'n/a',
                $summary['count'],
                (string) $summary['sum_line_total']
            ));
        }
    }

    private function reportPath(): string
    {
        $option = trim((string) $this->option('report'));

        if ($option === '') {
            return storage_path('app/recovery-inspection-report.json');
        }

        return $this->absolutePath($option);
    }

    private function resolveArchivePath(string $path): string
    {
        $absolute = $this->absolutePath(trim($path));
        $real = realpath($absolute);

        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException("Recovery ZIP is not readable: {$absolute}");
        }

        return $real;
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return storage_path();
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path)) {
            return $path;
        }

        return base_path(trim($path, '\\/'));
    }
}
