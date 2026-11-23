<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\PersonnelAttachment;
use App\Models\PersonnelEntry;
use App\Models\PersonnelEvent;
use App\Models\User;
use App\Services\Personnel\PersonnelDates;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Deliberately restricted to an isolated local rehearsal database. Never a production importer. */
class CrmMigrationRehearsal
{
    public const SOURCE = PersonnelEntry::SOURCE_CRM;

    public const DATABASE = 'inventory_crm_trial_20261122';

    private const LEAVE_STATUSES = [
        'pending' => 'pending', 'manager_approved' => 'pending', 'internal_approved' => 'pending',
        'accounting_approved' => 'pending', 'pending_manager' => 'pending', 'pending_internal' => 'pending', 'pending_account' => 'pending',
        'final_approved' => 'approved', 'approved' => 'approved', 'final_rejected' => 'rejected',
        'internal_rejected' => 'rejected', 'manager_rejected' => 'rejected', 'accounting_rejected' => 'rejected',
        'rejected' => 'rejected', 'cancelled' => 'cancelled',
    ];

    private function guard(): void
    {
        $connection = DB::connection();
        if (app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            return;
        }
        $config = $connection->getConfig();
        $modelConnection = Customer::query()->getConnection()->getName();
        if ($connection->getDatabaseName() !== self::DATABASE || DB::connection($modelConnection)->getDatabaseName() !== self::DATABASE || ! in_array($config['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
            throw ValidationException::withMessages(['database' => 'انتقال آزمایشی فقط در دیتابیس محلی مستقل مجاز است.']);
        }
    }

    public static function phone(?string $phone): string
    {
        $phone = preg_replace('/\D/', '', PersonnelDates::digits($phone ?? ''));
        if (str_starts_with($phone, '98') && strlen($phone) === 12) {
            $phone = '0'.substr($phone, 2);
        }

        return $phone;
    }

    private function userMap(array &$errors): array
    {
        $map = [];
        foreach (User::orderBy('id')->get() as $user) {
            $crm = (string) ($user->crm_user_id ?: $user->external_crm_id);
            if ($crm === '') {
                continue;
            }
            if (isset($map[$crm])) {
                $errors[] = 'duplicate_user_mapping:'.$crm;
            }
            $map[$crm] = $user->id;
        }

        return $map;
    }

    public function plan(array $snapshot, array $files = []): array
    {
        $errors = [];
        if (($snapshot['source'] ?? null) !== self::SOURCE || ($snapshot['schema_version'] ?? null) !== 1 || ! is_array($snapshot['tables'] ?? null)) {
            throw ValidationException::withMessages(['snapshot' => 'قالب نسخه CRM معتبر نیست.']);
        }
        if (($snapshot['flags']['report_review'] ?? false) !== false) {
            $errors[] = 'unsupported_staged_report_review';
        }
        $tables = $snapshot['tables'];
        $users = $this->userMap($errors);
        foreach ($tables['users'] ?? [] as $user) {
            if (! isset($users[(string) $user['id']])) {
                $errors[] = 'missing_user:'.$user['id'];
            }
        }
        $localCustomers = Customer::withTrashed()->whereNotNull('crm_customer_id')->get()->keyBy('crm_customer_id');
        $customerIds = [];
        foreach ($tables['customers'] ?? [] as $row) {
            $customerIds[(string) $row['id']] = true;
            if (! isset($users[(string) $row['user_id']])) {
                $errors[] = 'missing_customer_owner:'.$row['id'];
            }
            $existing = $localCustomers->get((string) $row['id']);
            if ($existing && ! DB::table('crm_import_records')->where('source', self::SOURCE)->where('source_table', 'customers')->where('source_id', $row['id'])->where('target_id', $existing->id)->exists()) {
                $errors[] = 'customer_identity_conflict:'.$row['id'];
            }
        }
        $leaves = collect($tables['leaves'] ?? [])->keyBy('id');
        $steps = collect($tables['leave_workflow_steps'] ?? [])->groupBy('leave_id');
        foreach ($leaves as $row) {
            if (! isset(self::LEAVE_STATUSES[$row['status']])) {
                $errors[] = 'unknown_leave_status:'.$row['id'];
            }
            $pending = empty($row['deleted_at']) && (self::LEAVE_STATUSES[$row['status']] ?? null) === 'pending';
            if ($pending && ($row['approval_route'] ?? null) === 'staged') {
                $current = $steps->get($row['id'], collect())->where('status', 'pending');
                if ($current->count() !== 1 || (int) $current->first()['approver_id'] !== (int) $row['assigned_approver_id']) {
                    $errors[] = 'invalid_current_leave_step:'.$row['id'];
                }
            }
            if ($pending && ($row['approval_route'] ?? null) === 'organization' && empty($row['assigned_approver_id'])) {
                $errors[] = 'missing_assigned_approver:'.$row['id'];
            }
            if ($pending && empty($row['approval_route']) && empty($tables['leave_approvers'])) {
                $errors[] = 'missing_legacy_approvers:'.$row['id'];
            }
            if (! in_array($row['approval_route'] ?? null, [null, '', 'staged', 'organization'], true)) {
                $errors[] = 'unknown_leave_route:'.$row['id'];
            }
        }
        foreach ($tables['reports'] ?? [] as $row) {
            if (! in_array($row['status'], ['draft', 'submitted', 'read'], true)) {
                $errors[] = 'unknown_report_status:'.$row['id'];
            }
        }
        foreach ($tables as $table => $rows) {
            $seen = [];
            foreach ($rows as $index => $row) {
                $id = $row['id'] ?? ($table === 'leave_approvers' ? $row['user_id'] : null);
                if (! $id || isset($seen[$id])) {
                    $errors[] = 'duplicate_or_missing_source_id:'.$table.':'.$index;
                }
                $seen[$id] = true;
                foreach (['user_id', 'approver_id', 'actor_id', 'principal_id', 'assigned_approver_id', 'substitute_user_id'] as $field) {
                    if (! empty($row[$field]) && ! isset($users[(string) $row[$field]])) {
                        $errors[] = 'unmapped_reference:'.$table.':'.$id.':'.$field;
                    }
                }
                if ($table === 'customer_notes' && ! isset($customerIds[(string) $row['customer_id']])) {
                    $errors[] = 'missing_note_customer:'.$id;
                }
            }
        }
        foreach ($tables['leave_workflow_steps'] ?? [] as $row) {
            if (! $leaves->has($row['leave_id'])) {
                $errors[] = 'orphan_leave_step:'.$row['id'];
            }
            if (! in_array($row['status'], ['pending', 'waiting', 'approved', 'rejected', 'cancelled'], true)) {
                $errors[] = 'unknown_step_status:'.$row['id'];
            }
        }
        foreach ($tables['report_attachments'] ?? [] as $row) {
            $file = $files[$row['file_path']] ?? null;
            if (($snapshot['attachment_inventory'][$row['file_path']]['missing'] ?? false) === true) {
                continue;
            }
            if (! $file || ! is_file($file) || is_link($file)) {
                $errors[] = 'missing_attachment:'.$row['id'];
            } elseif (isset($snapshot['attachment_inventory'][$row['file_path']]['sha256']) && ! hash_equals($snapshot['attachment_inventory'][$row['file_path']]['sha256'], hash_file('sha256', $file))) {
                $errors[] = 'attachment_hash_mismatch:'.$row['id'];
            }
        }
        $sourceIndex = [];
        foreach ($tables as $table => $rows) {
            foreach ($rows as $row) {
                $sourceIndex[$table][(string) ($row['id'] ?? $row['user_id'] ?? '')] = $row;
            }
        }
        foreach (DB::table('crm_import_records')->where('source', self::SOURCE)->get() as $old) {
            $source = $sourceIndex[$old->source_table][(string) $old->source_id] ?? null;
            if (! $source || ! hash_equals($old->source_hash, $this->hash($source))) {
                $errors[] = 'changed_imported_record:'.$old->source_table.':'.$old->source_id;
            }
        }
        $errors = array_values(array_unique($errors));
        $fileHashes = [];
        foreach ($files as $path => $file) {
            if (is_file($file)) {
                $fileHashes[$path] = hash_file('sha256', $file);
            }
        }

        return ['hash' => $this->hash([$snapshot, $users, $localCustomers->pluck('id', 'crm_customer_id')->all(), $fileHashes, $errors]), 'errors' => $errors, 'counts' => array_map('count', $tables), 'users' => $users];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function apply(array $snapshot, string $expectedHash, array $files = []): array
    {
        $this->guard();
        $written = [];
        try {
            return DB::transaction(function () use ($snapshot, $expectedHash, $files, &$written) {
                User::orderBy('id')->lockForUpdate()->get();
                $plan = $this->plan($snapshot, $files);
                if ($plan['errors'] || ! hash_equals($plan['hash'], $expectedHash)) {
                    throw ValidationException::withMessages(['plan' => 'نسخه، تطبیق یا داده مقصد تغییر کرده است؛ انتقال متوقف شد.']);
                }
                $tables = $snapshot['tables'];
                $users = $plan['users'];
                $customers = [];
                $entries = [];
                $new = ['customers' => 0, 'notes' => 0, 'leaves' => 0, 'reports' => 0, 'attachments' => 0];
                $getUser = fn ($id) => $users[(string) $id] ?? throw new \RuntimeException('Missing user mapping');
                $recorded = [];
                foreach (DB::table('crm_import_records')->where('source', self::SOURCE)->get(['source_table', 'source_id']) as $old) {
                    $recorded[$old->source_table.':'.$old->source_id] = true;
                }
                $record = function ($table, $row, $target = null, $targetId = null) use (&$recorded) {
                    $key = $table.':'.($row['id'] ?? $row['user_id']);
                    if (isset($recorded[$key])) {
                        return;
                    }
                    DB::table('crm_import_records')->insertOrIgnore(['source' => self::SOURCE, 'source_table' => $table, 'source_id' => $row['id'] ?? $row['user_id'], 'source_hash' => $this->hash($row), 'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'target_table' => $target, 'target_id' => $targetId, 'imported_at' => now()]);
                    $recorded[$key] = true;
                };
                $existing = DB::table('crm_import_records')->where('source', self::SOURCE)->get()->groupBy('source_table')->map(fn ($rows) => $rows->keyBy('source_id'));
                $phoneCounts = array_count_values(array_map(fn ($r) => self::phone($r['phone'] ?? ''), $tables['customers'] ?? []));
                foreach ($tables['customers'] ?? [] as $row) {
                    $old = $existing->get('customers', collect())->get($row['id']);
                    if ($old) {
                        $customers[$row['id']] = $old->target_id;

                        continue;
                    }
                    $phone = self::phone($row['phone'] ?? '');
                    // Ambiguous/missing telephone is retained in the source archive, never used to merge identities.
                    $mobile = $phone && ($phoneCounts[$phone] ?? 0) === 1 && ! Customer::withTrashed()->where('mobile', $phone)->exists() ? $phone : 'crm-trial-'.$row['id'];
                    $customer = Customer::create(['crm_customer_id' => (string) $row['id'], 'name' => $row['name'], 'first_name' => $row['name'], 'mobile' => $mobile, 'address' => $row['address'] ?? null, 'city' => $row['city'] ?? null, 'extra_description' => $row['DISC'] ?? null, 'sync_source' => 'crm-rehearsal', 'is_active' => true, 'opening_balance' => 0]);
                    $customer->assignment()->create(['seller_id' => $getUser($row['user_id']), 'version' => 1]);
                    $customers[$row['id']] = $customer->id;
                    $record('customers', $row, 'customers', $customer->id);
                    $new['customers']++;
                }
                foreach (['leave' => 'leaves', 'report' => 'reports'] as $kind => $table) {
                    foreach ($tables[$table] ?? [] as $row) {
                        $old = $existing->get($table, collect())->get($row['id']);
                        if ($old) {
                            $entries[$table][$row['id']] = $old->target_id;
                            if ($kind === 'report') {
                                $this->reportWatchers(PersonnelEntry::findOrFail($old->target_id), $row, $snapshot, $users);
                            }

                            continue;
                        }
                        $payload = ['crm_status' => $row['status'], 'crm_source_hash' => $this->hash($row)];
                        $status = $kind === 'leave' ? self::LEAVE_STATUSES[$row['status']] : $row['status'];
                        if ($kind === 'leave') {
                            $payload += ['leave_type' => $row['leave_type'], 'leave_unit' => $row['leave_unit'], 'start_time' => substr($row['start_time'] ?? '00:00', 0, 5), 'end_time' => substr($row['end_time'] ?? '23:59', 0, 5), 'substitute_user_id' => ! empty($row['substitute_user_id']) ? $getUser($row['substitute_user_id']) : null, 'crm_approval_route' => $row['approval_route'] ?: 'legacy', 'crm_deleted_at' => $row['deleted_at'] ?? null];
                            $payload['minutes'] = $row['leave_unit'] === 'ساعتی' ? max(0, (int) Carbon::parse($row['start_date'])->setTimeFromTimeString($payload['start_time'])->diffInMinutes(Carbon::parse($row['end_date'])->setTimeFromTimeString($payload['end_time']), false)) : 0;
                            if (! empty($row['deleted_at'])) {
                                $status = 'cancelled';
                            }
                        } else {
                            $payload += ['successful_calls' => (int) ($row['successful_calls'] ?? 0), 'unsuccessful_calls' => (int) ($row['unsuccessful_calls'] ?? 0), 'crm_feedback' => $row['feedback'] ?? null, 'crm_rating' => $row['rating'] ?? null];
                            $payload['crm_missing_attachments'] = collect($tables['report_attachments'] ?? [])->where('report_id', $row['id'])->filter(fn ($r) => ($snapshot['attachment_inventory'][$r['file_path']]['missing'] ?? false) === true)->pluck('id')->all();
                        }
                        $entry = PersonnelEntry::create(['kind' => $kind, 'user_id' => $getUser($row['user_id']), 'submission_key' => (string) Str::uuid(), 'source' => self::SOURCE, 'source_id' => $row['id'], 'title' => $kind === 'leave' ? $row['leave_type'].' '.$row['leave_unit'] : ($row['title'] ?? null), 'description' => $kind === 'leave' ? ($row['reason'] ?? null) : $row['description'], 'payload' => $payload, 'status' => $status, 'start_date' => $kind === 'leave' ? $row['start_date'] : null, 'end_date' => $kind === 'leave' ? $row['end_date'] : null, 'submitted_at' => $row['submitted_at'] ?? $row['created_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
                        $entries[$table][$row['id']] = $entry->id;
                        if ($kind === 'leave') {
                            $this->leaveSteps($entry, $row, $tables, $getUser);
                        } else {
                            $this->reportWatchers($entry, $row, $snapshot, $users);
                            // Legacy feedback has no recorded author: show it as source metadata, never attribute to the employee.
                        }
                        $record($table, $row, 'personnel_entries', $entry->id);
                        $new[$table]++;
                    }
                }
                foreach ($tables['leave_workflow_events'] ?? [] as $row) {
                    if ($existing->get('leave_workflow_events', collect())->has($row['id'])) {
                        continue;
                    }
                    $entryId = $entries['leaves'][$row['leave_id']] ?? throw new \RuntimeException('Orphan leave event');
                    $event = PersonnelEvent::create(['personnel_entry_id' => $entryId, 'actor_id' => $getUser($row['actor_id']), 'action' => ['approved' => 'approve', 'rejected' => 'reject'][$row['action']] ?? $row['action'], 'body' => $row['reason'] ?? null, 'meta' => ['crm_event_id' => $row['id'], 'principal_id' => ! empty($row['principal_id']) ? $getUser($row['principal_id']) : null, 'method' => $row['method'] ?? null], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'] ?? $row['created_at']]);
                    $record('leave_workflow_events', $row, 'personnel_events', $event->id);
                }
                foreach ($tables['customer_notes'] ?? [] as $row) {
                    if ($existing->get('customer_notes', collect())->has($row['id'])) {
                        continue;
                    }
                    $note = CustomerInteraction::create(['customer_id' => $customers[$row['customer_id']], 'author_id' => $getUser($row['user_id']), 'request_key' => (string) Str::uuid(), 'request_hash' => $this->hash($row), 'kind' => 'note', 'body' => trim(($row['title'] ?? '')."\n".($row['content'] ?? '')), 'legacy_source' => self::SOURCE, 'legacy_id' => (string) $row['id'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
                    $record('customer_notes', $row, 'customer_interactions', $note->id);
                    $new['notes']++;
                }
                foreach ($tables['report_attachments'] ?? [] as $row) {
                    if ($existing->get('report_attachments', collect())->has($row['id'])) {
                        continue;
                    }
                    if (($snapshot['attachment_inventory'][$row['file_path']]['missing'] ?? false) === true) {
                        $record('report_attachments', $row);

                        continue;
                    }
                    $file = $files[$row['file_path']];
                    $hash = hash_file('sha256', $file);
                    $path = 'crm-rehearsal/attachments/'.$hash;
                    if (! Storage::disk('local')->exists($path)) {
                        if (! Storage::disk('local')->put($path, file_get_contents($file))) {
                            throw new \RuntimeException('Attachment write failed');
                        }$written[] = $path;
                    }
                    $attachment = PersonnelAttachment::create(['personnel_entry_id' => $entries['reports'][$row['report_id']], 'path' => $path, 'name' => basename($row['file_path']), 'mime' => mime_content_type($file), 'size' => filesize($file), 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
                    $record('report_attachments', $row, 'personnel_attachments', $attachment->id);
                    $new['attachments']++;
                }
                foreach ($tables as $table => $rows) {
                    foreach ($rows as $row) {
                        $record($table, $row);
                    }
                }

                return $new;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($written);
            throw $e;
        }
    }

    private function leaveSteps(PersonnelEntry $entry, array $row, array $tables, callable $user): void
    {
        $stages = ['unit' => 'supervisor', 'internal' => 'internal_manager', 'executive' => 'executive_manager', 'substitute' => 'substitute'];
        $steps = collect($tables['leave_workflow_steps'] ?? [])->where('leave_id', $row['id'])->sortBy('position');
        foreach ($steps as $step) {
            $this->step($entry, $step['position'], $stages[$step['stage']] ?? $step['stage'], $user($step['approver_id']), ! empty($row['deleted_at']) && in_array($step['status'], ['pending', 'waiting']) ? 'cancelled' : $step['status'], $step['decided_at']);
        }
        if ($steps->isNotEmpty() || $entry->status !== 'pending') {
            return;
        }
        $ids = ($row['approval_route'] ?? null) === 'organization' ? [$row['assigned_approver_id']] : array_column($tables['leave_approvers'] ?? [], 'user_id');
        foreach ($ids as $index => $id) {
            if ($id != $row['user_id']) {
                $this->step($entry, $index + 1, ($row['approval_route'] ?? null) === 'organization' ? 'manager' : 'legacy_pool', $user($id), 'pending', null);
            }
        }
    }

    private function step(PersonnelEntry $entry, int $sequence, string $stage, int $user, string $status, ?string $decided): void
    {
        $entry->steps()->create(['sequence' => $sequence, 'stage' => $stage, 'approver_id' => $user, 'approver_name' => User::findOrFail($user)->name, 'status' => $status, 'decided_at' => $decided]);
    }

    private function reportWatchers(PersonnelEntry $entry, array $row, array $snapshot, array $users): void
    {
        $ids = array_filter($snapshot['manager_chains'][$row['user_id']] ?? [], fn ($id) => is_int($id));
        foreach ($snapshot['capabilities'] ?? [] as $cap) {
            if ($cap['reports_global'] ?? false) {
                $ids[] = $cap['id'];
            }
        }
        $existing = $entry->steps()->pluck('approver_id')->all();
        $sequence = (int) $entry->steps()->max('sequence');
        foreach (array_unique($ids) as $id) {
            if ($id == $row['user_id'] || ! isset($users[(string) $id]) || in_array($users[(string) $id], $existing, true)) {
                continue;
            }
            $this->step($entry, ++$sequence, 'manager', $users[(string) $id], 'watching', null);
        }
    }
}
