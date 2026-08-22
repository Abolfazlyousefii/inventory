<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Customer extends Authenticatable
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'crm_customer_id', 'sync_source', 'synced_at', 'crm_updated_at', 'last_crm_payload',
        'first_name', 'last_name', 'mobile', 'name', 'company_name', 'city', 'address',
        'postal_code', 'extra_description', 'notes', 'province_id', 'city_id',
        'opening_balance', 'reservation_tier', 'is_active', 'password', 'password_changed_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
            'province_id' => 'integer',
            'city_id' => 'integer',
            'opening_balance' => 'integer',
            'reservation_tier' => 'string',
            'synced_at' => 'datetime',
            'crm_updated_at' => 'datetime',
            'last_crm_payload' => 'array',
        ];
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function cityRelation(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function scopeWithBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['ledgers as debit_sum' => fn ($q) => $q->effectiveForBalance()->where('type', 'debit')], 'amount')
            ->withSum(['ledgers as credit_sum' => fn ($q) => $q->effectiveForBalance()->where('type', 'credit')], 'amount');
    }

    public function getBalanceAttribute(): int
    {
        return (int) ($this->opening_balance ?? 0) + (int) ($this->debit_sum ?? 0) - (int) ($this->credit_sum ?? 0);
    }

    public function getDebtAttribute(): int
    {
        return max($this->balance, 0);
    }

    public function getCreditAttribute(): int
    {
        return max(-$this->balance, 0);
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->name ?: trim(implode(' ', array_filter([$this->first_name, $this->last_name]))));
    }

    public function reservationTierLabel(): string
    {
        return match ($this->reservation_tier) {
            'vip' => 'VIP',
            'normal' => 'معمولی',
            'new_or_low_purchase' => 'جدید / کم‌خرید',
            default => 'معمولی پیش‌فرض',
        };
    }

    public function reservationTierBadgeClass(): string
    {
        return match ($this->reservation_tier) {
            'vip' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            'normal' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            'new_or_low_purchase' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            default => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        };
    }

    public function primaryPhone(): HasOne
    {
        return $this->hasOne(CustomerPhone::class)->where('is_primary', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }


}
