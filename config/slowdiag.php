<?php

return [
    'enabled' => (bool) env('SLOW_DIAG_ENABLED', false),
    'log_request_start' => (bool) env('SLOW_DIAG_LOG_REQUEST_START', false),

    // Requests slower than this are summarized when they finish.
    'request_ms' => max(100, (int) env('SLOW_DIAG_REQUEST_MS', 2000)),

    // Individual queries slower than this are logged as soon as they finish.
    'query_ms' => max(10, (int) env('SLOW_DIAG_QUERY_MS', 250)),

    // Prevent a single pathological request from flooding the diagnostic log.
    'max_slow_queries' => max(1, min(100, (int) env('SLOW_DIAG_MAX_SLOW_QUERIES', 20))),

    // SQL is logged without bindings and truncated to this length.
    'sql_max_length' => max(200, min(10000, (int) env('SLOW_DIAG_SQL_MAX_LENGTH', 2000))),
];
