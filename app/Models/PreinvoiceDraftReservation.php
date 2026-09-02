<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PreinvoiceDraftReservation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_RELEASABLE = 'releasable';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_RELEASED = 'released';

    public const STATUS_UNKNOWN = 'unknown';

    public const SCOPE_TEMPORARY_ONLINE = 'temporary_online';

    public const SCOPE_TEMPORARY_IN_PERSON = 'temporary_in_person';

    public const DEFAULT_ONLINE_STALE_MINUTES = 5;

    public const DEFAULT_IN_PERSON_STALE_MINUTES = 15;

    protected $fillable = [
        'token',
        'user_id',
        'preinvoice_order_id',
        'product_id',
        'variant_id',
        'quantity',
        'expires_at',
        'last_seen_at',
        'browser_session_id',
        'converted_at',
        'released_at',
        'released_by',
        'release_reason',
        'release_note',
        'reservation_scope',
        'reservation_tier',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'preinvoice_order_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'browser_session_id' => 'string',
        'converted_at' => 'datetime',
        'released_at' => 'datetime',
        'released_by' => 'integer',
        'release_reason' => 'string',
        'release_note' => 'string',
        'reservation_scope' => 'string',
        'reservation_tier' => 'string',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function scopeOpenTemporary(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('preinvoice_order_id'))
            ->whereNull($this->qualifyColumn('converted_at'))
            ->whereNull($this->qualifyColumn('released_at'))
            ->where($this->qualifyColumn('quantity'), '>', 0)
            ->whereIn($this->qualifyColumn('reservation_scope'), [
                self::SCOPE_TEMPORARY_ONLINE,
                self::SCOPE_TEMPORARY_IN_PERSON,
            ]);
    }

    public function scopeAbandonedTemporary(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $onlineCutoff = $at->copy()->subMinutes(max(1, $onlineMinutes));
        $inPersonCutoff = $at->copy()->subMinutes(max(1, $inPersonMinutes));

        return $query->openTemporary()->where(function (Builder $query) use ($at, $onlineCutoff, $inPersonCutoff): void {
            $query->where(function (Builder $query) use ($at, $onlineCutoff): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_ONLINE)
                    ->where(function (Builder $query) use ($at, $onlineCutoff): void {
                        $query->where($this->qualifyColumn('expires_at'), '<=', $at)
                            ->orWhere($this->qualifyColumn('last_seen_at'), '<=', $onlineCutoff);
                    });
            })->orWhere(function (Builder $query) use ($inPersonCutoff): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                    ->where($this->qualifyColumn('last_seen_at'), '<=', $inPersonCutoff);
            });
        });
    }

    public function scopeActiveTemporary(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $onlineCutoff = $at->copy()->subMinutes(max(1, $onlineMinutes));
        $inPersonCutoff = $at->copy()->subMinutes(max(1, $inPersonMinutes));

        return $query->openTemporary()->where(function (Builder $query) use ($at, $onlineCutoff, $inPersonCutoff): void {
            $query->where(function (Builder $query) use ($at, $onlineCutoff): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_ONLINE)
                    ->where(function (Builder $query) use ($at): void {
                        $query->whereNull($this->qualifyColumn('expires_at'))
                            ->orWhere($this->qualifyColumn('expires_at'), '>', $at);
                    })
                    ->where(function (Builder $query) use ($onlineCutoff): void {
                        $query->whereNull($this->qualifyColumn('last_seen_at'))
                            ->orWhere($this->qualifyColumn('last_seen_at'), '>', $onlineCutoff);
                    });
            })->orWhere(function (Builder $query) use ($inPersonCutoff): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                    ->where(function (Builder $query) use ($inPersonCutoff): void {
                        $query->whereNull($this->qualifyColumn('last_seen_at'))
                            ->orWhere($this->qualifyColumn('last_seen_at'), '>', $inPersonCutoff);
                    });
            });
        });
    }

    public function scopeExpiredTemporary(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->openTemporary()
            ->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_ONLINE)
            ->whereNotNull($this->qualifyColumn('expires_at'))
            ->where($this->qualifyColumn('expires_at'), '<=', $at);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('released_at'))
            ->where(function (Builder $query): void {
                $query->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
                    ->orWhereNotNull($this->qualifyColumn('converted_at'));
            });
    }

    public function scopeForManagementStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_ACTIVE => $query->activeTemporary(),
            self::STATUS_EXPIRED => $query->expiredTemporary(),
            self::STATUS_ABANDONED, self::STATUS_RELEASABLE => $query->abandonedTemporary(),
            self::STATUS_CONNECTED => $query->connected(),
            self::STATUS_RELEASED => $query->whereNotNull($this->qualifyColumn('released_at')),
            default => $query,
        };
    }

    public function scopeManagementSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search): void {
            $query->where($this->qualifyColumn('token'), 'like', "%{$search}%")
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%"))
                ->orWhereHas('variant', function (Builder $variant) use ($search): void {
                    $variant->where('variant_code', 'like', "%{$search}%")
                        ->orWhere('variety_code', 'like', "%{$search}%");
                });
        });
    }

    /** @return array<int, string> */
    public static function managementStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_EXPIRED,
            self::STATUS_ABANDONED,
            self::STATUS_RELEASABLE,
            self::STATUS_CONNECTED,
            self::STATUS_RELEASED,
        ];
    }

    public function managementStatus(?CarbonInterface $at = null): string
    {
        $at ??= now();

        if ($this->released_at !== null) {
            return self::STATUS_RELEASED;
        }

        if ($this->isConnectedToPreinvoice()) {
            return self::STATUS_CONNECTED;
        }

        if ($this->isExpiredTemporary($at)) {
            return self::STATUS_EXPIRED;
        }

        if ($this->isAbandoned($at)) {
            return self::STATUS_ABANDONED;
        }

        if ($this->isActiveTemporary($at)) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_UNKNOWN;
    }

    public function isActiveTemporary(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        return $this->isOpenTemporary()
            && ! $this->isAbandoned($at, $onlineMinutes, $inPersonMinutes);
    }

    public function isAbandoned(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        if (! $this->isOpenTemporary()) {
            return false;
        }

        $at ??= now();

        if ($this->reservation_scope === self::SCOPE_TEMPORARY_ONLINE) {
            return ($this->expires_at?->lte($at) ?? false)
                || ($this->last_seen_at?->lte($at->copy()->subMinutes(max(1, $onlineMinutes))) ?? false);
        }

        return $this->reservation_scope === self::SCOPE_TEMPORARY_IN_PERSON
            && ($this->last_seen_at?->lte($at->copy()->subMinutes(max(1, $inPersonMinutes))) ?? false);
    }

    public function isExpiredTemporary(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->isOpenTemporary()
            && $this->reservation_scope === self::SCOPE_TEMPORARY_ONLINE
            && ($this->expires_at?->lte($at) ?? false);
    }

    public function canBeManuallyReleased(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        return $this->isAbandoned($at, $onlineMinutes, $inPersonMinutes);
    }

    public function isConnectedToPreinvoice(): bool
    {
        return $this->released_at === null
            && ($this->preinvoice_order_id !== null || $this->converted_at !== null);
    }

    private function isOpenTemporary(): bool
    {
        return $this->preinvoice_order_id === null
            && $this->converted_at === null
            && $this->released_at === null
            && $this->quantity > 0
            && in_array($this->reservation_scope, [
                self::SCOPE_TEMPORARY_ONLINE,
                self::SCOPE_TEMPORARY_IN_PERSON,
            ], true);
    }
}
