<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recoveryFixtureRoot = storage_path('framework/testing/recovery-import-' . Str::uuid());
    File::ensureDirectoryExists($this->recoveryFixtureRoot);
});

afterEach(function (): void {
    if (isset($this->recoveryFixtureRoot) && File::isDirectory($this->recoveryFixtureRoot)) {
        File::deleteDirectory($this->recoveryFixtureRoot);
    }
});

/**
 * Build a syntactically valid recovery_delta ZIP so the write-mode guard cannot be
 * mistaken for an "invalid archive" failure.
 */
function recoveryDeltaFixtureZip(string $root): string
{
    $rows = [
        ['id' => 1, 'action' => 'created', 'description' => 'fixture', 'occurred_at' => '2026-08-27 10:00:00'],
    ];

    $dataFile = 'activity_logs.json';
    $payload = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $manifest = [
        'export_type' => 'recovery_delta',
        'project' => 'inventory',
        'source_label' => 'local-test',
        'generated_at' => '2026-08-27 10:00:00',
        'tables' => [
            'activity_logs' => ['file' => $dataFile, 'rows' => count($rows)],
        ],
        'file_sha256' => [
            $dataFile => hash('sha256', $payload),
        ],
    ];

    $zipPath = $root . DIRECTORY_SEPARATOR . 'recovery-delta-fixture.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString($dataFile, $payload);
    $zip->close();

    return $zipPath;
}

it('refuses to run recovery:import-delta without --dry-run', function (): void {
    $zip = recoveryDeltaFixtureZip($this->recoveryFixtureRoot);

    $exitCode = Artisan::call('recovery:import-delta', ['file' => $zip]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Recovery write mode is disabled');
});

it('keeps write mode blocked even with --force, --allow-partial and --overwrite-newer', function (array $options): void {
    $zip = recoveryDeltaFixtureZip($this->recoveryFixtureRoot);

    $exitCode = Artisan::call('recovery:import-delta', array_merge(['file' => $zip], $options));

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Recovery write mode is disabled');
})->with([
    'force' => [['--force' => true]],
    'allow-partial' => [['--allow-partial' => true]],
    'overwrite-newer' => [['--overwrite-newer' => true]],
    'all bypass flags together' => [[
        '--force' => true,
        '--allow-partial' => true,
        '--overwrite-newer' => true,
        '--with-snapshots' => true,
    ]],
]);

it('performs no database write statement when write mode is refused', function (): void {
    $zip = recoveryDeltaFixtureZip($this->recoveryFixtureRoot);

    $writes = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|truncate|drop|alter)\b/i', $event->sql) === 1) {
            $writes[] = $event->sql;
        }
    });

    Artisan::call('recovery:import-delta', ['file' => $zip, '--force' => true]);

    expect($writes)->toBe([]);
});

it('leaves the destination tables untouched when write mode is refused', function (): void {
    $zip = recoveryDeltaFixtureZip($this->recoveryFixtureRoot);

    $before = DB::table('activity_logs')->count();

    Artisan::call('recovery:import-delta', ['file' => $zip, '--force' => true]);

    expect(DB::table('activity_logs')->count())->toBe($before);
});

it('describes the importer as validate-and-compare only', function (): void {
    $description = Artisan::all()['recovery:import-delta']->getDescription();

    expect($description)->toContain('write mode is intentionally disabled');
});

it('requires an invoice or preinvoice id for recovery:inspect-delta', function (): void {
    $zip = recoveryDeltaFixtureZip($this->recoveryFixtureRoot);

    $exitCode = Artisan::call('recovery:inspect-delta', ['file' => $zip]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Provide at least one --invoice-id or --preinvoice-id.');
});

it('rejects an invalid archive for recovery:inspect-delta', function (): void {
    $bogus = $this->recoveryFixtureRoot . DIRECTORY_SEPARATOR . 'not-a-zip.zip';
    File::put($bogus, 'this is definitely not a zip archive');

    $exitCode = Artisan::call('recovery:inspect-delta', [
        'file' => $bogus,
        '--invoice-id' => [1],
    ]);

    expect($exitCode)->not->toBe(0);
});

it('rejects an archive whose export_type is not recovery_delta', function (): void {
    $zipPath = $this->recoveryFixtureRoot . DIRECTORY_SEPARATOR . 'wrong-type.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['export_type' => 'something_else']));
    $zip->close();

    $exitCode = Artisan::call('recovery:inspect-delta', [
        'file' => $zipPath,
        '--invoice-id' => [1],
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('not a recovery_delta export');
});

it('rejects an archive with no manifest for recovery:inspect-delta', function (): void {
    $zipPath = $this->recoveryFixtureRoot . DIRECTORY_SEPARATOR . 'no-manifest.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('invoices.json', '[]');
    $zip->close();

    $exitCode = Artisan::call('recovery:inspect-delta', [
        'file' => $zipPath,
        '--invoice-id' => [1],
    ]);

    expect($exitCode)->not->toBe(0);
});
