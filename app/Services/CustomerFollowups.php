<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerFollowup;
use App\Models\CustomerInteraction;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Contacts are immutable. Current customer ownership governs reading and writing. */
class CustomerFollowups
{
    public function __construct(private readonly CustomerWorkspace $workspace, private readonly CustomerOwnership $ownership) {}

    public function customer(User $actor, int $id): Customer
    {
        $this->workspace->authorize($actor);
        $customer = Customer::whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($this->ownership->visible(Customer::query(), $actor)->whereKey($id)->exists(), 404);

        return $customer;
    }

    public function record(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $customer = $this->customer($actor, $id);
            abort_unless($customer->is_active, 422, 'مشتری غیرفعال است.');
            $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
            $previous = CustomerInteraction::where('request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless($previous->customer_id === $id && $previous->author_id === $actor->id && hash_equals($previous->request_hash, $hash), 409, 'این درخواست قبلاً با اطلاعات دیگری ثبت شده است.');

                return [$previous, false];
            }
            $due = null;
            $owner = null;
            if (! empty($data['followup_title'])) {
                $date = JalaliDate::toGregorianDate($data['due_date'] ?? null);
                if (! $date) {
                    throw ValidationException::withMessages(['due_date' => 'تاریخ شمسی معتبر وارد کنید.']);
                }
                $due = Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$data['due_time'], config('app.timezone'));
                $owner = $customer->assignment()->with('seller')->first()?->seller;
                if (! $owner || ! $owner->is_active || ! $owner->can_access_erp) {
                    throw ValidationException::withMessages(['followup_title' => 'برای پیگیری، مشتری باید مسئول فعال داشته باشد.']);
                }
            }
            $note = CustomerInteraction::create(['customer_id' => $id, 'author_id' => $actor->id, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'kind' => $data['kind'], 'body' => $data['body']]);
            $task = $due ? CustomerFollowup::create(['customer_id' => $id, 'interaction_id' => $note->id, 'assigned_to' => $owner->id, 'title' => $data['followup_title'], 'due_at' => $due]) : null;
            ActivityLogger::logForActor($actor->id, 'customer_contact_recorded', $note, 'ثبت ارتباط با مشتری', ['customer_id' => $id, 'kind' => $note->kind, 'followup_id' => $task?->id]);

            return [$note, true];
        });
    }

    public function close(User $actor, int $customerId, int $taskId, array $data): CustomerFollowup
    {
        return DB::transaction(function () use ($actor, $customerId, $taskId, $data) {
            $this->customer($actor, $customerId);
            $task = CustomerFollowup::where('customer_id', $customerId)->whereKey($taskId)->lockForUpdate()->firstOrFail();
            abort_unless($task->version === (int) $data['version'] && $task->status === 'open', 409, 'این پیگیری تغییر کرده یا بسته شده است؛ فهرست را تازه کنید.');
            $task->update(['status' => $data['status'], 'result' => $data['result'] ?? null, 'closed_by' => $actor->id, 'closed_at' => now(), 'version' => $task->version + 1]);
            ActivityLogger::logForActor($actor->id, 'customer_followup_closed', $task, 'تعیین تکلیف پیگیری مشتری', ['customer_id' => $customerId, 'status' => $task->status, 'result' => $task->result]);

            return $task;
        });
    }

    public function listing(User $actor, string $scope)
    {
        $this->workspace->authorize($actor);
        $query = CustomerFollowup::query()->where('assigned_to', $actor->id)
            ->whereHas('customer', fn ($q) => $this->ownership->visible($q, $actor))->with(['customer:id,first_name,last_name,name,crm_customer_id', 'assignee:id,name', 'closer:id,name']);

        return match ($scope) {
            'today' => $query->where('status', 'open')->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]),
            'overdue' => $query->where('status', 'open')->where('due_at', '<', now()),
            'upcoming' => $query->where('status', 'open')->where('due_at', '>=', now()->addDay()->startOfDay()),
            'closed' => $query->whereIn('status',['done', 'cancelled']),
            default => $query->where('status','open'),
        };
    }
}
