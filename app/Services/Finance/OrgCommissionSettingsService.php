<?php

namespace App\Services\Finance;

use App\Models\OrgCommissionDepartment;
use App\Models\OrgCommissionDepartmentMember;
use App\Models\OrgCommissionDocument;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrgCommissionSettingsService
{
    // One unit is 0.0001 percent; the denominator for money is 1,000,000.
    public static function rateUnits(mixed $rate): int
    {
        $rate = strtr(trim((string) $rate), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']);
        if (! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/', $rate)) {
            throw ValidationException::withMessages(['rates' => 'درصد باید عددی بین صفر تا صد با حداکثر چهار رقم اعشار باشد.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $rate), 2, '');
        $units = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
        if ($units > 1000000) {
            throw ValidationException::withMessages(['rates' => 'درصد نمی‌تواند بیش از صد باشد.']);
        }

        return $units;
    }

    public static function formatRate(int $units): string
    {
        return intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function amount(int $base, int $units): int
    {
        if ($base < 0) {
            throw ValidationException::withMessages(['period_from' => 'کل پورسانت این بازه منفی است؛ ابتدا اسناد فروشندگان را بررسی کنید.']);
        }

        return intdiv($base, 1000000) * $units + intdiv(($base % 1000000) * $units + 500000, 1000000);
    }

    public function snapshot(bool $lock = false, bool $requireConfigured = true): array
    {
        $query = OrgCommissionDepartment::where('is_active', true)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $departments = [];
        foreach ($query->get() as $dept) {
            $membersQuery = $dept->activeMembers()->reorder('id');
            if ($lock) {
                $membersQuery->lockForUpdate();
            }
            $members = $membersQuery->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->role, 'percentage' => $m->percentage])->all();
            $departments[] = ['id' => $dept->id, 'name' => $dept->name, 'members' => $members];
        }
        $snapshot = ['departments' => $departments];
        $snapshot['version'] = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($requireConfigured) {
            $this->validateSnapshot($snapshot);
        }

        return $snapshot;
    }

    private function validateSnapshot(array $snapshot): void
    {
        $total = 0;
        foreach ($snapshot['departments'] as $dept) {
            foreach ($dept['members'] as $member) {
                if ($member['percentage'] === null) {
                    throw ValidationException::withMessages(['rates' => 'درصد '.$member['name'].' در تنظیمات پورسانت اداری مشخص نشده است.']);
                }
                $total += self::rateUnits($member['percentage']);
            }
        }
        if ($total <= 0 || $total > 1000000) {
            throw ValidationException::withMessages(['rates' => 'مجموع درصد افراد فعال باید بیشتر از صفر و حداکثر صد درصد باشد.']);
        }
    }

    public function saveRates(array $rates, string $version, int $actorId): void
    {
        DB::transaction(function () use ($rates, $version, $actorId) {
            $before = $this->snapshot(true, false);
            if (! hash_equals($before['version'], $version)) {
                throw ValidationException::withMessages(['settings_version' => 'تنظیمات تغییر کرده است؛ صفحه را تازه کنید و دوباره ذخیره کنید.']);
            }
            $ids = collect($before['departments'])->flatMap(fn ($d) => $d['members'])->pluck('id')->sort()->values()->all();
            $submitted = array_map('intval', array_keys($rates));
            sort($submitted);
            if ($ids !== $submitted) {
                throw ValidationException::withMessages(['rates' => 'درصد همه افراد فعال باید مشخص شود.']);
            }
            foreach ($rates as $id => $rate) {
                OrgCommissionDepartmentMember::whereKey($id)->update(['percentage' => self::formatRate(self::rateUnits($rate))]);
            }
            $after = $this->snapshot(false, true);
            ActivityLogger::logForActor($actorId, 'org_commission_settings.updated', OrgCommissionDepartment::findOrFail($before['departments'][0]['id']), 'درصد ثابت افراد در تنظیمات پورسانت اداری تغییر کرد.', ['before' => $before, 'after' => $after]);
        });
    }

    public function fromDocument(OrgCommissionDocument $document): array
    {
        if ($document->commission_settings_snapshot) {
            return $document->commission_settings_snapshot;
        }
        if ((int) $document->total_seller_commission <= 0) {
            throw ValidationException::withMessages(['document' => 'استخراج درصد از سند با مبنای صفر امکان‌پذیر نیست.']);
        }
        $document->loadMissing(['allocations.department', 'allocations.memberShares.member']);
        $departments = [];
        foreach ($document->allocations as $a) {
            $members = [];
            foreach ($a->memberShares as $share) {
                // Legacy shares are money snapshots; retain four decimal percent precision.
                $units = (int) round((int) $share->share_amount / (int) $document->total_seller_commission * 1000000);
                $members[] = ['id' => $share->member_id, 'name' => $share->member->name, 'role' => $share->member->role, 'percentage' => self::formatRate($units)];
            }
            $departments[] = ['id' => $a->department_id, 'name' => $a->department->name, 'members' => $members];
        }
        $snapshot = ['departments' => $departments, 'source_document' => $document->document_number];
        $snapshot['version'] = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->validateSnapshot($snapshot);

        return $snapshot;
    }

    public function initializeFromDocument(OrgCommissionDocument $document, int $actorId): array
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = OrgCommissionDocument::lockForUpdate()->findOrFail($document->id);
            if (! in_array($document->status, ['confirmed', 'finalized'], true)) {
                throw ValidationException::withMessages(['document' => 'سند مبنا باید تأیید یا نهایی شده باشد.']);
            }
            $source = $this->fromDocument($document);
            $current = $this->snapshot(true, false);
            $rates = collect($source['departments'])->flatMap(fn ($d) => $d['members'])->pluck('percentage', 'id')->all();
            foreach ($current['departments'] as $dept) {
                foreach ($dept['members'] as $member) {
                    if ($member['percentage'] !== null) {
                        throw ValidationException::withMessages(['rates' => 'تنظیمات قبلاً مقداردهی شده است؛ بازنویسی خودکار مجاز نیست.']);
                    }
                    if (! array_key_exists($member['id'], $rates)) {
                        throw ValidationException::withMessages(['rates' => 'عضو فعال '.$member['name'].' در سند مبنا سهم ندارد.']);
                    }
                }
            }
            $activeIds = collect($current['departments'])->flatMap(fn ($d) => $d['members'])->pluck('id')->all();
            $this->saveRates(array_intersect_key($rates, array_flip($activeIds)), $current['version'], $actorId);
            ActivityLogger::logForActor($actorId, 'org_commission_settings.initialized', $document, 'درصد ثابت افراد از سند تأییدشده استخراج شد؛ سند قبلی تغییر نکرد.', ['source_document' => $document->document_number]);

            return $this->snapshot();
        });
    }

    public function allocations(array $snapshot, int $base): array
    {
        $this->validateSnapshot($snapshot);
        $result = [];
        foreach ($snapshot['departments'] as $dept) {
            $members = [];
            $units = 0;
            $amount = 0;
            foreach ($dept['members'] as $member) {
                $rate = self::rateUnits($member['percentage']);
                $share = self::amount($base, $rate);
                $units += $rate;
                $amount += $share;
                $members[] = $member + ['share_amount' => $share];
            }
            if ($units > 0) {
                $result[] = ['department_id' => $dept['id'], 'name' => $dept['name'], 'percentage' => self::formatRate($units), 'allocated_amount' => $amount, 'members' => $members];
            }
        }

        return $result;
    }
}
