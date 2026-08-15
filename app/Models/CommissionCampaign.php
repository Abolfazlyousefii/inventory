<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionCampaign extends Model
{
    protected $fillable = ['name', 'bonus_percentage', 'start_at', 'end_at', 'notes', 'archived_at', 'created_by', 'updated_by'];

    protected $casts = ['bonus_percentage' => 'decimal:4', 'start_at' => 'datetime', 'end_at' => 'datetime', 'archived_at' => 'datetime'];

    public function targets()
    {
        return $this->hasMany(CommissionCampaignTarget::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDerivedStatusAttribute(): string
    {
        return match (true) {
            $this->archived_at !== null => 'archived',
            now()->lt($this->start_at) => 'scheduled',
            now()->gte($this->end_at) => 'expired',
            default => 'active',
        };
    }
}
