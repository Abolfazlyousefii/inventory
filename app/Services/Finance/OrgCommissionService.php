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
            $totalSeller = $this->totalSellerCommission($data['period_from'], $data['period_to']);

            $document = OrgCommissionDocument::create([
                'uuid' => (string) Str::uuid(),
                'document_number' => 'PENDING-'.Str::uuid(),
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'total_seller_commission' => $totalSeller,
                'total_allocated' => 0,
                'notes' => $data['notes'] ?? null,
                'status' => OrgCommissionDocument::STATUS_DRAFT,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $document->update([
                'document_number' => 'OC-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT),
            ]);

            if (! empty($data['allocations'])) {
                $this->saveAllocations($document, $data['allocations'], $totalSeller);
            }

            ActivityLogger::log('org_commission_document.created', $document, 'سند پورسانت اداری ایجاد شد.');

            return $document->fresh(['allocations.department', 'allocations.memberShares.member']);
        });
    }

    public function updateDocument(OrgCommissionDocument $document, array $data, User $actor): OrgCommissionDocument
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل ویرایش هستند.']);
        }

        return DB::transaction(function () use ($document, $data, $actor) {
            $document = OrgCommissionDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! $document->isDraft()) {
                throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل ویرایش هستند.']);
            }

            $totalSeller = $this->totalSellerCommission($data['period_from'], $data['period_to']);

            $document->update([
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'total_seller_commission' => $totalSeller,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            $document->allocations()->each(function (OrgCommissionAllocation $allocation) {
                $allocation->memberShares()->delete();
            });
            $document->allocations()->delete();

            $document->update(['total_allocated' => 0]);

            if (! empty($data['allocations'])) {
                $this->saveAllocations($document, $data['allocations'], $totalSeller);
            }

            ActivityLogger::log('org_commission_document.updated', $document, 'سند پورسانت اداری ویرایش شد.');

            return $document->fresh(['allocations.department', 'allocations.memberShares.member']);
        });
    }

    private function saveAllocations(OrgCommissionDocument $document, array $allocations, int $totalSeller): void
    {
        $totalAllocated = 0;

        foreach ($allocations as $allocationData) {
            $percentage = (float) $allocationData['percentage'];
            if ($percentage <= 0) {
                continue;
            }

            $amount = (int) round($totalSeller * $percentage / 100);
            $totalAllocated += $amount;

            $allocation = $document->allocations()->create([
                'department_id' => (int) $allocationData['department_id'],
                'percentage' => number_format($percentage, 4, '.', ''),
                'allocated_amount' => $amount,
            ]);

            if (! empty($allocationData['members'])) {
                $this->saveMemberShares($allocation, $allocationData['members']);
            }
        }

        $document->update(['total_allocated' => $totalAllocated]);
    }

    private function saveMemberShares(OrgCommissionAllocation $allocation, array $members): void
    {
        foreach ($members as $memberData) {
            $shareAmount = (int) $memberData['share_amount'];
            if ($shareAmount <= 0) {
                continue;
            }

            $allocation->memberShares()->create([
                'member_id' => (int) $memberData['member_id'],
                'share_amount' => $shareAmount,
                'notes' => $memberData['notes'] ?? null,
            ]);
        }
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
