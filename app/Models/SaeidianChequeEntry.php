<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaeidianChequeEntry extends Model
{
    protected $fillable = ['kind', 'cheque_id', 'amount', 'effective_date', 'cheque_number', 'party', 'notes', 'created_by', 'submission_token', 'category', 'status', 'status_reason', 'status_changed_by', 'status_changed_at'];

    protected $casts = ['amount' => 'integer', 'effective_date' => 'date', 'status_changed_at' => 'datetime'];
    public function cheque() { return $this->belongsTo(Cheque::class); }

    // Display projection only: never rewrite the original deposit date.
    public function calendarDate(): string
    {
        if ($this->kind !== 'deposit' || ! $this->cheque?->due_date) return $this->effective_date->toDateString();
        $due=$this->cheque->due_date;
        if ($due->year < 1700) {
            try {
                $j=\Morilog\Jalali\Jalalian::fromFormat('Y/m/d',$due->format('Y/m/d'));
                if($j->format('Y/m/d')!==$due->format('Y/m/d')) return $this->effective_date->toDateString();
                return $j->toCarbon()->toDateString();
            } catch(\Throwable) {return $this->effective_date->toDateString();}
        }
        return $due->toDateString();
    }
    public function isBounced(): bool { return $this->status === 'bounced' || ($this->kind === 'deposit' && in_array($this->cheque?->status,['bounced','returned'],true)); }
    public function categoryLabel(): string {return ['salary'=>'حقوق','insurance'=>'بیمه','other'=>'سایر'][$this->category] ?? 'تعهد';}
}
