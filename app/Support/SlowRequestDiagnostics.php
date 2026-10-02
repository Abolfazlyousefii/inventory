<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SlowRequestDiagnostics
{
    private bool $active = false;

    private ?Request $request = null;

    private int $startedAtNs = 0;

    private string $requestId = '';

    private int $queryCount = 0;

    private float $queryTotalMs = 0.0;

    private int $slowQueryCount = 0;

    private array $slowQueries = [];

    public function begin(Request $request): void
    {
        $this->active = true;
        $this->request = $request;
        $this->startedAtNs = hrtime(true);
        $this->requestId = (string) Str::uuid();
        $this->queryCount = 0;
        $this->queryTotalMs = 0.0;
        $this->slowQueryCount = 0;
        $this->slowQueries = [];

        if ((bool) config('slowdiag.log_request_start')) {
            Log::channel('slowdiag')->info('SLOW_DIAG_REQUEST_START', $this->baseContext());
        }
    }

    public function recordQuery(QueryExecuted $query): void
    {
        if (! $this->active) {
            return;
        }

        $timeMs = max(0.0, (float) $query->time);
        $this->queryCount++;
        $this->queryTotalMs += $timeMs;

        if ($timeMs < (float) config('slowdiag.query_ms', 250)) {
            return;
        }

        $this->slowQueryCount++;

        $entry = [
            'connection' => $query->connectionName,
            'time_ms' => round($timeMs, 2),
            'bindings_count' => count($query->bindings),
            'sql' => $this->sanitizeSql($query->sql),
        ];

        $limit = (int) config('slowdiag.max_slow_queries', 20);

        if (count($this->slowQueries) < $limit) {
            $this->slowQueries[] = $entry;
        }

        if ($this->slowQueryCount <= $limit) {
            Log::channel('slowdiag')->warning(
                'SLOW_DIAG_SLOW_QUERY',
                array_merge($this->baseContext(), $entry),
            );
        }
    }

    public function finish(?Response $response, ?Throwable $exception = null): void
    {
        if (! $this->active) {
            return;
        }

        $durationMs = $this->elapsedMs();

        // Stop collecting before resolving the user or writing logs, so diagnostic
        // work itself is never counted as application database activity.
        $this->active = false;

        if ($durationMs < (float) config('slowdiag.request_ms', 2000)) {
            return;
        }

        $context = array_merge($this->baseContext(), [
            'user_id' => $this->safeUserId(),
            'status' => $response?->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
            'query_count' => $this->queryCount,
            'query_total_ms' => round($this->queryTotalMs, 2),
            'slow_query_count' => $this->slowQueryCount,
            'slow_queries' => $this->slowQueries,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'exception' => $exception ? $exception::class : null,
        ]);

        Log::channel('slowdiag')->warning('SLOW_DIAG_SLOW_REQUEST', $context);
    }

    public function shutdown(): void
    {
        if (! $this->active) {
            return;
        }

        $durationMs = $this->elapsedMs();
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $fatal = $lastError !== null && in_array($lastError['type'] ?? null, $fatalTypes, true);

        if ($durationMs < (float) config('slowdiag.request_ms', 2000) && ! $fatal) {
            return;
        }

        $this->active = false;

        Log::channel('slowdiag')->critical('SLOW_DIAG_REQUEST_SHUTDOWN', array_merge(
            $this->baseContext(),
            [
                'duration_ms' => round($durationMs, 2),
                'query_count' => $this->queryCount,
                'query_total_ms' => round($this->queryTotalMs, 2),
                'slow_query_count' => $this->slowQueryCount,
                'slow_queries' => $this->slowQueries,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'fatal_error' => $fatal ? [
                    'type' => $lastError['type'] ?? null,
                    'message' => $lastError['message'] ?? null,
                    'file' => $lastError['file'] ?? null,
                    'line' => $lastError['line'] ?? null,
                ] : null,
            ],
        ));
    }

    private function baseContext(): array
    {
        return [
            'request_id' => $this->requestId,
            'pid' => getmypid() ?: null,
            'method' => $this->request?->getMethod(),
            'path' => $this->request?->path(),
            'route' => $this->request?->route()?->getName(),
        ];
    }

    private function safeUserId(): int|string|null
    {
        try {
            return $this->request?->user()?->getAuthIdentifier();
        } catch (Throwable) {
            return null;
        }
    }

    private function elapsedMs(): float
    {
        if ($this->startedAtNs <= 0) {
            return 0.0;
        }

        return (hrtime(true) - $this->startedAtNs) / 1_000_000;
    }

    private function sanitizeSql(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "'?'", $sql) ?? $sql;
        $sql = preg_replace('/\\s+/', ' ', trim($sql)) ?? trim($sql);

        return Str::limit(
            $sql,
            (int) config('slowdiag.sql_max_length', 2000),
            '...',
        );
    }
}
