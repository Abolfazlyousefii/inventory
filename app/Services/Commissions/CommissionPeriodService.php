<?php

namespace App\Services\Commissions;

use App\Models\CommissionPeriod;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;

class CommissionPeriodService
{
    public function createForDate(CarbonInterface|string $date, ?int $cycleDay = null): CommissionPeriod
    {
        $date = Carbon::parse($date)->startOfDay();
        $existing = CommissionPeriod::query()->where('start_at', '<=', $date)->where('end_at', '>', $date)->first();
        if ($existing) {
            return $existing;
        }
        $cycleDay ??= CommissionSetting::current()->cycle_day;
        $jalali = Jalalian::fromDateTime($date);
        $candidate = $this->boundary($jalali->getYear(), $jalali->getMonth(), $cycleDay);
        $startJalali = $date->lt($candidate) ? $jalali->subMonths() : $jalali;
        $start = $this->boundary($startJalali->getYear(), $startJalali->getMonth(), $cycleDay);
        $next = $startJalali->addMonths();
        $end = $this->boundary($next->getYear(), $next->getMonth(), $cycleDay);

        $previousEnd = CommissionPeriod::query()->where('end_at', '>', $start)->where('end_at', '<=', $date)->max('end_at');
        if ($previousEnd) {
            $start = Carbon::parse($previousEnd);
        }
        $nextStart = CommissionPeriod::query()->where('start_at', '>', $date)->where('start_at', '<', $end)->min('start_at');
        if ($nextStart) {
            $end = Carbon::parse($nextStart);
        }
        if (! $end->gt($start)) {
            throw ValidationException::withMessages(['period' => 'مرز دوره جدید با دوره‌های موجود سازگار نیست.']);
        }

        return DB::transaction(function () use ($start, $end, $cycleDay) {
            CommissionSetting::current();
            CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $overlap = CommissionPeriod::query()->where('start_at', '<', $end)->where('end_at', '>', $start)->lockForUpdate()->first();
            if ($overlap) {
                return $overlap;
            }

            $period = CommissionPeriod::query()->create([
                'label' => JalaliDate::date($start).' تا '.JalaliDate::date($end),
                'start_at' => $start, 'end_at' => $end,
                'cycle_day_snapshot' => $cycleDay,
                'status' => CommissionPeriod::STATUS_OPEN,
                'needs_recalculation' => false,
            ]);

            ActivityLogger::log('commission_period.created', $period, 'دوره پورسانت با مرزهای ثابت ایجاد شد.', [
                'cycle_day_snapshot' => $cycleDay,
            ]);

            return $period;
        });
    }

    public function updateCycleDay(mixed $cycleDay, User $actor): CommissionSetting
    {
        $normalized = strtr(trim((string) $cycleDay), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (! ctype_digit($normalized) || (int) $normalized < 1 || (int) $normalized > 31) {
            throw ValidationException::withMessages(['cycle_day' => 'روز شروع چرخه باید بین ۱ تا ۳۱ باشد.']);
        }
        $setting = CommissionSetting::current();
        $previousCycleDay = $setting->cycle_day;
        $setting->update(['cycle_day' => (int) $normalized, 'updated_by' => $actor->id]);
        ActivityLogger::log('commission_setting.updated', $setting, 'روز شروع چرخه پورسانت برای دوره‌های آینده تغییر کرد.', [
            'old_cycle_day' => $previousCycleDay,
            'new_cycle_day' => (int) $normalized,
            'actor_id' => $actor->id,
        ]);

        return $setting;
    }

    private function boundary(int $year, int $month, int $requestedDay): Carbon
    {
        $probe = new Jalalian($year, $month, 1);

        return (new Jalalian($year, $month, min($requestedDay, $probe->getMonthDays())))->toCarbon()->startOfDay();
    }
}
