<?php

namespace App\Listeners;

use App\Events\InvoiceChangeAuditRequested;
use App\Services\SiteOrderItemNotesSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LogInvoiceChangeAudit
{
    private static ?string $correlationId = null;

    /** @var array<string, bool> */
    private static array $processed = [];

    public function __construct(
        private readonly SiteOrderItemNotesSyncService $siteNotesSync,
    ) {
    }

    public function handle(InvoiceChangeAuditRequested $event): void
    {
        $correlationId = $this->correlationId();
        $fingerprint = $this->fingerprint($correlationId, $event);

        // Laravel event auto-discovery + manual registration can cause the
        // same listener to be invoked twice. Never write notes twice.
        if (isset(self::$processed[$fingerprint])) {
            return;
        }
        self::$processed[$fingerprint] = true;

        $actorUserId = auth()->id();
        $requestContext = $this->requestContext();

        Log::info('[invoice-notes-audit] ' . $event->action, array_merge([
            'audit_correlation_id' => $correlationId,
            'actor_user_id' => $actorUserId,
            'request' => $requestContext,
            'logged_at' => now()->toISOString(),
        ], $event->payload));

        try {
            $result = $this->siteNotesSync->sync(
                $event->action,
                $event->payload,
                $correlationId,
                $actorUserId !== null ? (int) $actorUserId : null,
                $requestContext,
            );

            Log::info('[site-order-notes-sync] listener result', [
                'audit_correlation_id' => $correlationId,
                'action' => $event->action,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            // ERP operation is already committed at this point. A temporary
            // site database problem must be visible in logs but must not make
            // the completed ERP operation look rolled back.
            Log::error('[site-order-notes-sync] failed', [
                'audit_correlation_id' => $correlationId,
                'action' => $event->action,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'invoice_id' => $event->payload['invoice_id'] ?? null,
            ]);
        }
    }

    private function fingerprint(string $correlationId, InvoiceChangeAuditRequested $event): string
    {
        return hash('sha256', $correlationId . '|' . $event->action . '|' . json_encode($event->payload));
    }

    private function correlationId(): string
    {
        if (self::$correlationId !== null) {
            return self::$correlationId;
        }

        $requestId = null;
        if (! app()->runningInConsole()) {
            $requestId = request()->header('X-Request-ID')
                ?: request()->header('X-Correlation-ID');
        }

        return self::$correlationId = $requestId ?: (string) Str::uuid();
    }

    private function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return [
                'source' => 'console',
                'command' => $_SERVER['argv'] ?? [],
                'change_reason' => null,
                'change_note' => null,
            ];
        }

        $request = request();

        return [
            'source' => 'http',
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'change_reason' => $request->input('change_reason')
                ?? $request->input('edit_reason')
                ?? $request->input('cancellation_reason'),
            'change_note' => $request->input('change_note')
                ?? $request->input('collection_note')
                ?? $request->input('cancellation_note')
                ?? $request->input('note'),
        ];
    }
}
