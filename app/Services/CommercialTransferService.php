<?php

namespace App\Services;

use App\Models\CommercialTransferDocument;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PageAccessCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialTransferService
{
    public function __construct(private readonly CustomerOwnership $ownership, private readonly SalesDocumentSellerReassignmentService $invoiceTransfers) {}

    public function allowed(User $user, string $kind): bool
    {
        return $user->is_active && $user->can_access_erp && match ($kind) {
            'customer' => $this->ownership->canManage($user),'invoice' => PageAccessCatalog::userCan($user, 'page.commercial.invoice_reassignments'),default => false
        };
    }

    public function authorize(User $user, string $kind): void
    {
        abort_unless($this->allowed($user, $kind), 403);
    }

    public function target(string $kind, int $id)
    {
        return ($kind === 'customer' ? Customer::query() : Invoice::query())->lockForUpdate()->findOrFail($id);
    }

    public function snapshot(string $kind, $target): array
    {
        if ($kind === 'customer') {
            $assignment = $target->assignment()->lockForUpdate()->first();

            return ['seller_id' => $assignment?->seller_id, 'version' => $assignment?->version ?? 0, 'stamp' => hash('sha256', json_encode([$assignment?->getAttributes(), DB::table('activity_logs')->where('subject_type', Customer::class)->where('subject_id', $target->id)->whereIn('action', ['customer_seller_transferred', 'customer_seller_assigned', 'customer_transfer_cancelled'])->max('id')]))];
        }
        $order = $target->preinvoiceOrder()->lockForUpdate()->first();
        $claims = SellerSalesDocumentItem::query()->where('active_invoice_id', $target->id)->orderBy('id')->get()->map->getAttributes()->all();

        return ['seller_id' => $target->effective_seller_id ? (int) $target->effective_seller_id : null, 'invoice_seller_id' => $target->seller_id, 'preinvoice_seller_id' => $order?->seller_id, 'stamp' => hash('sha256', json_encode([$target->getAttributes(), $order?->getAttributes(), $claims, SellerReassignmentAudit::where('invoice_id', $target->id)->max('id')]))];
    }

    public function token(string $kind, int $id, array $snapshot): string
    {
        return hash_hmac('sha256', json_encode([$kind, $id, $snapshot]), config('app.key'));
    }

    public function label(string $kind, $target): string
    {
        return mb_substr($kind === 'customer' ? $target->display_name : 'فاکتور '.$target->uuid.' · '.($target->customer_name ?: $target->customer?->display_name), 0, 255);
    }

    private function seller(int $id): User
    {
        return User::query()->activeErpUsers()->find($id) ?? throw ValidationException::withMessages(['to_id' => 'همکار مقصد باید فعال و مجاز به ورود باشد.']);
    }

    public function source(int $id): ?User
    {
        if ($id === 0) {
            return null;
        }

        // Former employees remain valid sources for transferring their records.
        // Destination eligibility is enforced independently by seller().
        return User::query()->find($id)
            ?? throw ValidationException::withMessages(['from_id' => 'همکار مبدأ پیدا نشد.']);
    }

    private function event(CommercialTransferDocument $doc, User $actor, string $action, ?string $reason = null): void
    {
        $doc->events()->create(['actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
    }

    private function number(): int
    {
        $row = DB::table('commercial_transfer_sequences')->where('key', 'transfer')->lockForUpdate()->first();
        DB::table('commercial_transfer_sequences')->where('key', 'transfer')->increment('next_number');

        return (int) $row->next_number;
    }

    public function save(array $data, User $actor, ?CommercialTransferDocument $document = null): array
    {
        $this->authorize($actor, $data['kind']);
        if (! trim($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'دلیل انتقال را بنویسید.']);
        }

        return DB::transaction(function () use ($data, $actor, $document) {
            // Serializes duplicate requests from the same actor without allocating a second number.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $document) {
                $existing = CommercialTransferDocument::where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    abort_unless($existing->created_by === $actor->id, 409);
                    $this->authorize($actor, $existing->kind);
                    $sameSelection = $existing->items()->pluck('target_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
                        === collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                    abort_unless($sameSelection && $existing->kind === $data['kind']
                        && (int) $existing->from_id === (int) ($data['from_id'] ?? 0)
                        && (int) $existing->to_id === (int) $data['to_id']
                        && $existing->reason === trim($data['reason']), 409, 'این درخواست قبلاً با اطلاعات دیگری ذخیره شده است؛ سند قبلی را باز کنید.');

                    return [$existing, false];
                }
            }
            $to = $this->seller((int) $data['to_id']);
            $from = $this->source((int) ($data['from_id'] ?? 0));
            if ($from?->id === $to->id) {
                throw ValidationException::withMessages(['to_id' => 'مبدأ و مقصد باید متفاوت باشند.']);
            }
            $doc = $document ? CommercialTransferDocument::lockForUpdate()->findOrFail($document->id) : new CommercialTransferDocument;
            if ($doc->exists) {
                $this->authorize($actor, $doc->kind);
                abort_unless($doc->status === 'draft' && $doc->version === (int) $data['version'], 409, 'سند تغییر کرده یا دیگر پیش‌نویس نیست.');
                $doc->version++;
            } else {
                $doc->number = $this->number();
                $doc->submission_key = $data['submission_key'];
                $doc->created_by = $actor->id;
                $doc->actor_name = $actor->name;
            }
            $snapshots = [];
            foreach (collect($data['items'])->sortBy('id') as $row) {
                $target = $this->target($data['kind'], (int) $row['id']);
                $snapshot = $this->snapshot($data['kind'], $target);
                abort_unless(hash_equals($this->token($data['kind'], $target->id, $snapshot), $row['token']), 409, 'اطلاعات یکی از موارد تغییر کرده است؛ دوباره انتخاب کنید.');
                abort_unless(($snapshot['seller_id'] ?? null) === $from?->id, 409, 'مسئول فعلی با مبدأ انتخابی مطابقت ندارد.');
                $snapshots[] = ['target_id' => $target->id, 'label' => $this->label($data['kind'], $target), 'token' => $row['token'], 'before' => $snapshot];
            }
            $doc->fill(['kind' => $data['kind'], 'status' => 'draft', 'from_id' => $from?->id, 'to_id' => $to->id, 'from_name' => $from?->name ?? 'بدون مسئول', 'to_name' => $to->name, 'reason' => trim($data['reason'])]);
            $doc->save();
            $doc->items()->delete();
            $doc->items()->createMany($snapshots);
            $this->event($doc, $actor, $document ? 'draft_updated' : 'created');
            if ($data['action'] === 'register') {
                $this->execute($doc, $actor);
            }

            return [$doc->refresh(), ! $document];
        });
    }

    private function execute(CommercialTransferDocument $doc, User $actor): void
    {
        $this->source((int) $doc->from_id);
        $to = $this->seller($doc->to_id);
        $items = $doc->items()->get();
        $targets = [];
        $snapshots = [];
        // Validate every selection before executing any ownership changes.
        foreach ($items as $item) {
            $target = $this->target($doc->kind, $item->target_id);
            $snapshot = $this->snapshot($doc->kind, $target);
            abort_unless(hash_equals($item->token, $this->token($doc->kind, $target->id, $snapshot)), 409, 'اطلاعات موارد از زمان ذخیره پیش‌نویس تغییر کرده است؛ موارد را دوباره انتخاب کنید.');
            $targets[$item->id] = $target;
            $snapshots[$item->id] = $snapshot;
        }
        foreach ($items as $item) {
            $target = $targets[$item->id];
            $snapshot = $snapshots[$item->id];
            if ($doc->kind === 'customer') {
                $this->ownership->transfer($target, $to, $actor, (int) $snapshot['version'], $doc->reason);
            } else {
                $this->invoiceTransfers->reassignInvoiceSeller($target, $to, $actor, $doc->reason, true, 'transfer_document', $doc->submission_key);
            }
        }
        foreach ($items as $item) {
            $item->after = $this->snapshot($doc->kind, $targets[$item->id]->fresh());
            $item->save();
        }
        $doc->update(['status' => 'registered', 'registered_at' => now()]);
        $this->event($doc, $actor, 'registered');
    }

    public function register(CommercialTransferDocument $document, User $actor, int $version): CommercialTransferDocument
    {
        $this->authorize($actor, $document->kind);

        return DB::transaction(function () use ($document, $actor, $version) {
            $doc = CommercialTransferDocument::lockForUpdate()->findOrFail($document->id);
            abort_unless($doc->status === 'draft' && $doc->version === $version, 409, 'سند تغییر کرده یا ثبت شده است.');
            $this->execute($doc, $actor);
            $doc->increment('version');

            return $doc->refresh();
        });
    }

    public function remove(CommercialTransferDocument $document, User $actor, int $version): void
    {
        $this->authorize($actor, $document->kind);
        DB::transaction(function () use ($document, $actor, $version) {
            $doc = CommercialTransferDocument::lockForUpdate()->findOrFail($document->id);
            abort_unless($doc->status === 'draft' && $doc->version === $version, 409, 'فقط پیش‌نویس قابل حذف است.');
            $doc->update(['status' => 'deleted', 'version' => $version + 1]);
            $this->event($doc, $actor, 'draft_deleted');
        });
    }

    public function cancel(CommercialTransferDocument $document, User $actor, int $version, string $reason): void
    {
        $this->authorize($actor, $document->kind);
        if (! trim($reason)) {
            throw ValidationException::withMessages(['reason' => 'دلیل لغو را بنویسید.']);
        }
        DB::transaction(function () use ($document, $actor, $version, $reason) {
            $doc = CommercialTransferDocument::lockForUpdate()->findOrFail($document->id);
            abort_unless($doc->status === 'registered' && $doc->version === $version && ! $doc->legacy_key, 409, 'این سند قابل لغو نیست یا نسخه آن تغییر کرده است.');
            $items = $doc->items()->get();
            $targets = [];
            $snapshots = [];
            foreach ($items as $item) {
                $target = $this->target($doc->kind, $item->target_id);
                $current = $this->snapshot($doc->kind, $target);
                abort_unless($current === $item->after, 409, 'بعد از این سند، اطلاعات یا مسئول یکی از موارد تغییر کرده است؛ لغو خودکار مجاز نیست.');
                $targets[$item->id] = $target;
                $snapshots[$item->id] = $current;
            }
            foreach ($items as $item) {
                $target = $targets[$item->id];
                $current = $snapshots[$item->id];
                $old = $item->before['seller_id'];
                if ($doc->kind === 'customer') {
                    if ($old) {
                        $this->ownership->transfer($target, $this->seller((int) $old), $actor, (int) $current['version'], 'لغو سند '.$doc->number.' — '.$reason);
                    } else {
                        $target->assignment()->delete();
                        ActivityLogger::logForActor($actor->id, 'customer_transfer_cancelled', $target, 'لغو تخصیص طبق سند '.$doc->number, ['reason' => $reason]);
                    }
                } else {
                    abort_unless($old, 409, 'فاکتور پیش از انتقال فروشنده مشخص نداشته است؛ لغو نیازمند بررسی است.');
                    $this->invoiceTransfers->reassignInvoiceSeller($target, $this->seller((int) $old), $actor, 'لغو سند '.$doc->number.' — '.$reason, false, 'transfer_cancel', $doc->submission_key);
                    DB::table('invoices')->where('id', $target->id)->update(['seller_id' => $item->before['invoice_seller_id'] ?? null]);
                    if ($target->preinvoice_order_id) {
                        DB::table('preinvoice_orders')->where('id', $target->preinvoice_order_id)->update(['seller_id' => $item->before['preinvoice_seller_id'], 'updated_at' => now()]);
                    }
                }
            }
            $doc->update(['status' => 'cancelled', 'cancelled_at' => now(), 'version' => $version + 1]);
            $this->event($doc, $actor, 'cancelled', $reason);
        });
    }
}
