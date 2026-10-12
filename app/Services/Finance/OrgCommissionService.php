<?php

namespace App\Services\Finance;

use App\Models\OrgCommissionAllocation;
use App\Models\OrgCommissionDepartment;
use App\Models\OrgCommissionDocument;
use App\Models\SellerSalesDocument;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrgCommissionService
{
    // ── واحدها ──────────────────────────────────

    public function createDepartment(array $data, int $actorId): OrgCommissionDepartment
    {
        return DB::transaction(function () use ($data, $actorId) {
            $department = OrgCommissionDepartment::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => true,
                'sort_order' => (int) OrgCommissionDepartment::max('sort_order') + 1,
                'created_by' => $actorId,
            ]);

            ActivityLogger::log('org_commission_department.created', $department, 'واحد پورسانت اداری ایجاد شد.');

            return $department;
        });
    }

    public function updateDepartment(OrgCommissionDepartment $dept, array $data): OrgCommissionDepartment
    {
        return DB::transaction(function () use ($dept, $data) {
            $dept = OrgCommissionDepartment::lockForUpdate()->findOrFail($dept->id);
            $dept->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            ActivityLogger::log('org_commission_department.updated', $dept, 'واحد پورسانت اداری ویرایش شد.');

            return $dept->fresh();
        });
    }

    public function toggleDepartment(OrgCommissionDepartment $dept): void
    {
        DB::transaction(function () use ($dept) {
            $dept = OrgCommissionDepartment::lockForUpdate()->findOrFail($dept->id);
            $dept->update(['is_active' => ! $dept->is_active]);

            ActivityLogger::log(
                'org_commission_department.toggled',
                $dept,
                $dept->is_active ? 'واحد پورسانت اداری فعال شد.' : 'واحد پورسانت اداری غیرفعال شد.'
            );
        });
    }

    // ── اعضا ────────────────────────────────────

    public function syncMembers(OrgCommissionDepartment $dept, array $members): void
    {
        DB::transaction(function () use ($dept, $members) {
            $dept = OrgCommissionDepartment::lockForUpdate()->findOrFail($dept->id);
            $dept->members()->update(['is_active' => false]);

            foreach ($members as $index => $memberData) {
                $name = trim((string) $memberData['name']);
                if ($name === '') {
                    continue;
                }

                $existing = $dept->members()->where('name', $name)->first();
                if ($existing) {
                    $existing->update([
                        'role' => $memberData['role'] ?? null,
                        'is_active' => true,
                        'sort_order' => $index,
                    ]);
                } else {
                    $dept->members()->create([
                        'name' => $name,
                        'role' => $memberData['role'] ?? null,
                        'is_active' => true,
                        'sort_order' => $index,
                    ]);
                }
            }

            ActivityLogger::log('org_commission_department.members_synced', $dept, 'اعضای واحد پورسانت اداری به‌روزرسانی شد.');
        });
    }

    // ── محاسبه کل پورسانت فروشنده‌ها ───────────

    public function totalSellerCommission(string $dateFrom, string $dateTo): int
    {
        return (int) SellerSalesDocument::query()
            ->whereIn('status', [
                SellerSalesDocument::STATUS_CONFIRMED,
                SellerSalesDocument::STATUS_FINALIZED,
            ])
            ->where('period_from', '>=', $dateFrom)
            ->where('period_to', '<=', $dateTo)
            ->sum('net_commission_amount');
    }

    // ── سند ─────────────────────────────────────

    public function createDocument(array $data, User $actor): OrgCommissionDocument
    {
        return DB::transaction(function () use ($data, $actor) {
            $settings = app(OrgCommissionSettingsService::class);
            $snapshot = $settings->snapshot(true);
            if (! hash_equals($snapshot['version'], (string) ($data['settings_version'] ?? ''))) {
                throw ValidationException::withMessages(['settings_version' => 'درصدها تغییر کرده است؛ صفحه را تازه کنید و سند را دوباره ثبت کنید.']);
            }
            $totalSeller = $this->totalSellerCommission($data['period_from'], $data['period_to']);
            $allocations = $settings->allocations($snapshot, $totalSeller);
            $document = OrgCommissionDocument::create([
                'uuid' => (string) Str::uuid(),
                'document_number' => 'PENDING-'.Str::uuid(),
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'total_seller_commission' => $totalSeller,
                'total_allocated' => 0,
                'commission_settings_snapshot' => $snapshot,
                'notes' => $data['notes'] ?? null,
                'status' => OrgCommissionDocument::STATUS_DRAFT,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $document->update(['document_number' => 'OC-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);
            $this->saveCalculatedAllocations($document, $allocations);
            ActivityLogger::logForActor($actor->id, 'org_commission_document.created', $document, 'سند پورسانت اداری با درصدهای ثابت تنظیمات ایجاد شد.', ['settings_version' => $snapshot['version']]);

            return $document->fresh(['allocations.department', 'allocations.memberShares.member']);
        });
    }

    public function updateDocument(OrgCommissionDocument $document, array $data, User $actor): OrgCommissionDocument
    {
        return DB::transaction(function () use ($document, $data, $actor) {
            $document = OrgCommissionDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! $document->isDraft()) {
                throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل ویرایش هستند.']);
            }
            $settings = app(OrgCommissionSettingsService::class);
            // Recalculating a draft retains its own rates, even after settings change.
            $snapshot = $settings->fromDocument($document);
            $totalSeller = $this->totalSellerCommission($data['period_from'], $data['period_to']);
            $allocations = $settings->allocations($snapshot, $totalSeller);
            $document->allocations()->each(fn (OrgCommissionAllocation $a) => $a->memberShares()->delete());
            $document->allocations()->delete();
            $document->update([
                'period_from' => $data['period_from'], 'period_to' => $data['period_to'],
                'total_seller_commission' => $totalSeller, 'commission_settings_snapshot' => $snapshot,
                'notes' => $data['notes'] ?? null, 'updated_by' => $actor->id,
            ]);
            $this->saveCalculatedAllocations($document, $allocations);
            ActivityLogger::logForActor($actor->id, 'org_commission_document.updated', $document, 'سند پورسانت اداری با حفظ درصدهای زمان صدور محاسبه شد.');

            return $document->fresh(['allocations.department', 'allocations.memberShares.member']);
        });
    }

    private function saveCalculatedAllocations(OrgCommissionDocument $document, array $allocations): void
    {
        $total = 0;
        foreach ($allocations as $data) {
            $allocation = $document->allocations()->create([
                'department_id' => $data['department_id'], 'percentage' => $data['percentage'],
                'allocated_amount' => $data['allocated_amount'],
            ]);
            foreach ($data['members'] as $member) {
                $allocation->memberShares()->create(['member_id' => $member['id'], 'share_amount' => $member['share_amount']]);
            }
            $total += $data['allocated_amount'];
        }
        $document->update(['total_allocated' => $total]);
    }

    public function confirmDocument(OrgCommissionDocument $document, int $actorId): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل تأیید هستند.']);
        }

        if ($document->allocations()->count() === 0) {
            throw ValidationException::withMessages(['document' => 'سند بدون تخصیص قابل تأیید نیست.']);
        }

        DB::transaction(function () use ($document, $actorId) {
            $document->update([
                'status' => OrgCommissionDocument::STATUS_CONFIRMED,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ]);
            ActivityLogger::log('org_commission_document.confirmed', $document, 'سند پورسانت اداری تأیید شد.');
        });
    }

    public function finalizeDocument(OrgCommissionDocument $document, int $actorId): void
    {
        if (! $document->isConfirmed()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد تأیید‌شده قابل نهایی‌سازی هستند.']);
        }

        DB::transaction(function () use ($document, $actorId) {
            $document->update([
                'status' => OrgCommissionDocument::STATUS_FINALIZED,
                'finalized_by' => $actorId,
                'finalized_at' => now(),
            ]);
            ActivityLogger::log('org_commission_document.finalized', $document, 'سند پورسانت اداری نهایی شد.');
        });
    }

    public function deleteDraft(OrgCommissionDocument $document): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل حذف هستند.']);
        }

        DB::transaction(function () use ($document) {
            ActivityLogger::log('org_commission_document.deleted', $document, 'سند پیش‌نویس پورسانت اداری حذف شد.');

            $document->allocations()->each(fn (OrgCommissionAllocation $allocation) => $allocation->memberShares()->delete());
            $document->allocations()->delete();
            $document->delete();
        });
    }
}
