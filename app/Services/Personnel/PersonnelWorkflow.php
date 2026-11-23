<?php

namespace App\Services\Personnel;

use App\Models\PersonnelEntry;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;

class PersonnelWorkflow
{
    public function __construct(private OrganizationHierarchy $hierarchy, private PersonnelAccess $access) {}

    public function leaveData(User $author, array $data): array
    {
        $start = PersonnelDates::parse($data['start_date'], 'start_date');
        $end = PersonnelDates::parse($data['end_date'], 'end_date');
        $hourly = $data['leave_unit'] === 'ساعتی';
        $from = $hourly ? PersonnelDates::digits($data['start_time'] ?? '') : '00:00';
        $to = $hourly ? PersonnelDates::digits($data['end_time'] ?? '') : '23:59';
        if ($end->lt($start)) {
            $this->invalid('end_date', 'پایان مرخصی نباید قبل از شروع باشد.');
        }
        if ($hourly && (! $start->isSameDay($end) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $from) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $to) || $to <= $from)) {
            $this->invalid('end_time', 'مرخصی ساعتی باید در همان روز و با ساعت پایان بعد از شروع باشد.');
        }
        if ($data['leave_type'] === 'اضطراری' && ! $hourly) {
            $this->invalid('leave_unit', 'مرخصی اضطراری فقط به صورت ساعتی است.');
        }
        if (! $hourly && $start->lt(now()->addHours(48))) {
            $this->invalid('start_date', 'مرخصی روزانه باید حداقل ۴۸ ساعت پیش از شروع ثبت شود.');
        }
        $substitute = null;
        if (! empty($data['substitute_user_id'])) {
            $substitute = User::find($data['substitute_user_id']);
            if (! $substitute || $substitute->id === $author->id || ! PersonnelAccess::active($substitute) || $substitute->department !== $author->department || ! $this->access->canUse($substitute, 'leave')) {
                $this->invalid('substitute_user_id', 'جایگزین باید فرد دیگری از همین واحد، فعال و دارای دسترسی مرخصی باشد.');
            }
        }
        if (filled($author->department) && ! $substitute) {
            $this->invalid('substitute_user_id', 'انتخاب جایگزین از همین واحد الزامی است.');
        }
        $minutes = $hourly ? (int) $start->copy()->setTimeFromTimeString($from)->diffInMinutes($end->copy()->setTimeFromTimeString($to)) : 0;
        if ($data['leave_type'] === 'اضطراری') {
            $month = Jalalian::fromCarbon($start);
            $monthStart = (new Jalalian($month->getYear(), $month->getMonth(), 1))->toCarbon()->startOfDay();
            $monthEnd = Jalalian::fromCarbon($monthStart)->addMonths(1)->toCarbon()->startOfDay();
            $previous = PersonnelEntry::where('kind', 'leave')->where('user_id', $author->id)->where('status', '!=', 'cancelled')->where('payload->leave_type', 'اضطراری');
            if ((clone $previous)->where('start_date', '>=', $monthStart)->where('start_date', '<', $monthEnd)->count() >= 3) {
                $this->invalid('leave_type', 'سهمیه سه درخواست اضطراری این ماه شمسی تکمیل شده است؛ درخواست ردشده هم شمرده می‌شود.');
            }
            $used = (clone $previous)->whereDate('start_date', $start)->get()->sum(fn ($entry) => (int) ($entry->payload['minutes'] ?? 0));
            if ($minutes > 240 || $used + $minutes > 240) {
                $this->invalid('end_time', 'مجموع مرخصی اضطراری یک روز حداکثر چهار ساعت است.');
            }
        }
        $overlaps = PersonnelEntry::where('kind', 'leave')->where('user_id', $author->id)->whereIn('status', ['pending', 'approved'])->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get();
        foreach ($overlaps as $entry) {
            $old = $entry->payload;
            if (! $hourly || ($old['leave_unit'] ?? 'روزانه') !== 'ساعتی' || ($from < ($old['end_time'] ?? '23:59') && $to > ($old['start_time'] ?? '00:00'))) {
                $this->invalid('start_date', 'این بازه با یک مرخصی فعال قبلی هم‌پوشانی دارد.');
            }
        }

        return ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'description' => $data['reason'] ?? null, 'title' => $data['leave_type'].' '.$data['leave_unit'], 'payload' => ['leave_type' => $data['leave_type'], 'leave_unit' => $data['leave_unit'], 'start_time' => $from, 'end_time' => $to, 'minutes' => $minutes, 'substitute_user_id' => $substitute?->id]];
    }

    public function snapshot(PersonnelEntry $entry, User $author): void
    {
        try {
            $chain = $this->hierarchy->managerChain($author);
        } catch (\DomainException $e) {
            $this->invalid('organization', $e->getMessage());
        }
        $route = [];
        if ($entry->kind === 'leave' && ! empty($entry->payload['substitute_user_id'])) {
            $route[] = ['substitute', User::find($entry->payload['substitute_user_id'])];
        }
        $unitAdded = false;
        $internalAdded = $author->organization_level === 'internal_manager';
        foreach ($chain as $manager) {
            // CRM's active organization flow: one unit stage, then internal and executive.
            if ($entry->kind === 'leave') {
                $level = $manager->organization_level;
                if (! in_array($level, ['internal_manager', 'executive_manager'], true)) {
                    if ($unitAdded || in_array($author->organization_level, ['supervisor', 'department_manager', 'internal_manager'], true)) {
                        continue;
                    }
                    $unitAdded = true;
                }
                if ($level === 'internal_manager') {
                    $internalAdded = true;
                }
            }
            if (! PersonnelAccess::active($manager)) {
                $this->invalid('organization', 'یکی از مدیران مسیر غیرفعال است؛ ابتدا ساختار پرسنل اصلاح شود.');
            }
            $route[] = [$manager->organization_level ?: 'manager', $manager];
            if ($entry->kind === 'leave' && $manager->organization_level === 'executive_manager') {
                break;
            }
        }
        if (! $route) {
            $this->invalid('organization', 'مدیر مسئول برای شما تعیین نشده است؛ ابتدا ساختار سازمانی تکمیل شود.');
        }
        if ($entry->kind === 'leave' && ! collect($route)->contains(fn ($item) => $item[0] === 'executive_manager')) {
            $this->invalid('organization', 'مدیر اجرایی مستقل در مسیر تأیید مرخصی تعیین نشده است.');
        }
        if ($entry->kind === 'leave' && ! $internalAdded) {
            $this->invalid('organization', 'مدیر داخلی در مسیر تأیید مرخصی تعیین نشده است.');
        }
        $seen = [];
        foreach ($route as $index => [$stage,$manager]) {
            if (! $manager || $manager->id === $author->id || isset($seen[$manager->id])) {
                $this->invalid('organization', 'مسیر تأیید تکراری یا شامل خود درخواست‌کننده است.');
            }
            $seen[$manager->id] = true;
            $allowed = $stage === 'substitute' ? $this->access->canUse($manager, 'leave') : $this->access->canReview($manager, $entry->kind);
            if (! $allowed) {
                $this->invalid('organization', 'دسترسی بررسی برای یکی از مسئولان مسیر تعیین نشده است؛ مدیر سامانه باید آن را تنظیم کند.');
            }
            $entry->steps()->create(['sequence' => $index + 1, 'stage' => $stage, 'approver_id' => $manager->id, 'approver_name' => $manager->name, 'status' => $entry->kind === 'report' ? 'watching' : ($index === 0 ? 'pending' : 'waiting')]);
        }
    }

    public function reportSubmit(PersonnelEntry $entry, User $author): void
    {
        if (! $author->hasPermission('personnel.reports.submit_multiple') && PersonnelEntry::where('kind', 'report')->where('user_id', $author->id)->whereKeyNot($entry->id)->whereDate('submitted_at', today())->exists()) {
            $this->invalid('description', 'امکان ارسال بیش از یک گزارش در روز وجود ندارد.');
        }
        if (! $entry->steps()->exists()) {
            $this->snapshot($entry, $author);
        }
        $entry->status = 'submitted';
        $entry->submitted_at ??= now();
    }

    public function event(PersonnelEntry $entry, User $actor, string $action, ?string $body = null, array $meta = []): void
    {
        $entry->events()->create(['actor_id' => $actor->id, 'action' => $action, 'body' => $body, 'meta' => $meta]);
    }

    public function notify(PersonnelEntry $entry, array $recipients): void
    {
        foreach (array_unique($recipients) as $id) {
            app(NotificationService::class)->notifyUserAfterCommit($id, 'personnel.workflow', 'به‌روزرسانی درخواست پرسنلی', 'برای مشاهده جزئیات به پرسنل و همکاری مراجعه کنید.', route('personnel.'.($entry->kind === 'leave' ? 'leaves' : 'reports').'.show', $entry->id));
        }
    }

    public function decide(PersonnelEntry $entry, User $actor, string $action, int $version, ?string $reason): PersonnelEntry
    {
        return DB::transaction(function () use ($entry, $actor, $action, $version, $reason) {
            $entry = PersonnelEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_if($entry->version !== $version, 409, 'اطلاعات تغییر کرده است؛ جزئیات را دوباره باز کنید.');
            if ($action === 'cancel') {
                abort_unless($entry->user_id === $actor->id && $this->access->canUse($actor, 'leave') && $entry->status === 'pending', 403);
                $entry->steps()->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled']);
                $entry->status = 'cancelled';
            } else {
                abort_unless($entry->status === 'pending' && $this->access->decide($actor, $entry), 403);
                $legacyPool = $entry->source === PersonnelEntry::SOURCE_CRM && ($entry->payload['crm_approval_route'] ?? null) === 'legacy';
                $step = $entry->steps()->where('status', 'pending')->when($legacyPool, fn ($q) => $q->where('stage', 'legacy_pool')->where('approver_id', $actor->id))->firstOrFail();
                $step->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'decided_at' => now()]);
                if ($legacyPool) $entry->steps()->where('status', 'pending')->update(['status' => 'cancelled']);
                if ($action === 'reject') {
                    if (! filled($reason)) {
                        $this->invalid('body', 'علت رد درخواست را بنویسید.');
                    }
                    $entry->steps()->where('status', 'waiting')->update(['status' => 'cancelled']);
                    $entry->status = 'rejected';
                } else {
                    $next = $entry->steps()->where('status', 'waiting')->first();
                    if ($next) {
                        $next->update(['status' => 'pending']);
                        $this->notify($entry, [$next->approver_id]);
                    } else {
                        $entry->status = 'approved';
                    }
                }
            }
            $entry->version++;
            $entry->save();
            $this->event($entry,$actor,$action,$reason);
            $this->notify($entry,[$entry->user_id]);

            return $entry;
        });
    }

    private function invalid(string $key,string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
