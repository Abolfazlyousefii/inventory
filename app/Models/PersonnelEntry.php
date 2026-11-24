<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelEntry extends Model
{
    public const SOURCE_CRM = 'ariya-crm';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'start_date' => 'date', 'end_date' => 'date', 'submitted_at' => 'datetime', 'version' => 'integer'];

    public const STATUSES = ['draft' => 'پیش‌نویس', 'pending' => 'در انتظار بررسی', 'submitted' => 'ارسال‌شده', 'read' => 'بررسی‌شده', 'needs_revision' => 'نیازمند اصلاح', 'approved' => 'تأیید نهایی', 'rejected' => 'ردشده', 'cancelled' => 'لغوشده'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function steps()
    {
        return $this->allSteps()->whereNull('personnel_steps.superseded_at')->whereNotIn('personnel_steps.stage',['migration_observer','rehearsal_viewer'])->whereHas('approver',fn($q)=>$q->where('show_in_organization_chart',true));
    }

    public function allSteps()
    {
        return $this->hasMany(PersonnelStep::class)->orderBy('sequence');
    }

    public function events()
    {
        return $this->hasMany(PersonnelEvent::class)->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(PersonnelAttachment::class);
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
