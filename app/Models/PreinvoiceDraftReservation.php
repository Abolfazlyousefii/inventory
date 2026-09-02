<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreinvoiceDraftReservation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_RELEASABLE = 'releasable';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_RELEASED = 'released';

    public const STATUS_UNKNOWN = 'unknown';

    public const IMPORTANCE_CRITICAL = 'critical';

    public const IMPORTANCE_HIGH = 'high';

    public const IMPORTANCE_REVIEW = 'review';

    public const IMPORTANCE_NORMAL = 'normal';

    public const QUICK_ACTIONABLE = 'actionable';

    public const QUICK_REVIEW = 'review';

    public const QUICK_ACTIVE = 'active';

    public const OLD_RESERVATION_MINUTES = 60;

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

    public function activeDrafts(): HasMany
    {
        return $this->hasMany(PreinvoiceOrder::class, 'draft_token', 'token')
            ->where('status', PreinvoiceOrder::STATUS_DRAFT);
    }

    public function scopeOrphaned(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $onlineCutoff = $at->copy()->subMinutes(max(1, $onlineMinutes));
        $inPersonCutoff = $at->copy()->subMinutes(max(1, $inPersonMinutes));

        return $query
            ->whereNull($this->qualifyColumn('released_at'))
            ->whereNull($this->qualifyColumn('preinvoice_order_id'))
            ->where($this->qualifyColumn('quantity'), '>', 0)
            ->whereDoesntHave('activeDrafts')
            ->where(function (Builder $query) use ($onlineCutoff, $inPersonCutoff): void {
                $query->whereNull($this->qualifyColumn('last_seen_at'))
                    ->orWhere(function (Builder $query) use ($inPersonCutoff): void {
                        $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                            ->where($this->qualifyColumn('last_seen_at'), '<=', $inPersonCutoff);
                    })
                    ->orWhere(function (Builder $query) use ($onlineCutoff): void {
                        $query->where(function (Builder $query): void {
                            $query->whereNull($this->qualifyColumn('reservation_scope'))
                                ->orWhere($this->qualifyColumn('reservation_scope'), '!=', self::SCOPE_TEMPORARY_IN_PERSON);
                        })->where($this->qualifyColumn('last_seen_at'), '<=', $onlineCutoff);
                    });
            });
    }

    public function scopeExcludeOrphaned(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        return $query->whereNotIn(
            $this->qualifyColumn('id'),
            self::query()
                ->select($this->qualifyColumn('id'))
                ->orphaned($onlineMinutes, $inPersonMinutes, $at),
        );
    }

    public function scopeOpenTemporary(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('preinvoice_order_id'))
            ->whereNull($this->qualifyColumn('converted_at'))
            ->whereNull($this->qualifyColumn('released_at'))
            ->whereNull($this->qualifyColumn('release_reason'))
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

    public function scopeCleanupCandidates(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $onlineCutoff = $at->copy()->subMinutes(max(1, $onlineMinutes));
        $inPersonCutoff = $at->copy()->subMinutes(max(1, $inPersonMinutes));

        return $query
            ->openTemporary()
            ->whereDoesntHave('activeDrafts')
            ->where(function (Builder $query) use ($onlineCutoff, $inPersonCutoff): void {
                $query->where(function (Builder $query) use ($onlineCutoff): void {
                    $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_ONLINE)
                        ->where(function (Builder $query) use ($onlineCutoff): void {
                            $query->whereNull($this->qualifyColumn('last_seen_at'))
                                ->orWhere($this->qualifyColumn('last_seen_at'), '<=', $onlineCutoff);
                        });
                })->orWhere(function (Builder $query) use ($inPersonCutoff): void {
                    $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                        ->where(function (Builder $query) use ($inPersonCutoff): void {
                            $query->whereNull($this->qualifyColumn('last_seen_at'))
                                ->orWhere($this->qualifyColumn('last_seen_at'), '<=', $inPersonCutoff);
                        });
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
            ->whereNull($this->qualifyColumn('release_reason'))
            ->where(function (Builder $query): void {
                $query->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
                    ->orWhereNotNull($this->qualifyColumn('converted_at'));
            });
    }

    public function scopeNeedsManagementReview(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->abandonedTemporary()
                ->orWhere(function (Builder $query): void {
                    $query->whereNull($this->qualifyColumn('preinvoice_order_id'))
                        ->whereNull($this->qualifyColumn('converted_at'))
                        ->whereNull($this->qualifyColumn('released_at'))
                        ->whereNull($this->qualifyColumn('release_reason'))
                        ->where($this->qualifyColumn('quantity'), '>', 0)
                        ->where(function (Builder $query): void {
                            $query->whereNull($this->qualifyColumn('reservation_scope'))
                                ->orWhereNotIn($this->qualifyColumn('reservation_scope'), [
                                    self::SCOPE_TEMPORARY_ONLINE,
                                    self::SCOPE_TEMPORARY_IN_PERSON,
                                ]);
                        });
                });
        });
    }

    public function scopeForManagementQuickFilter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            self::QUICK_ACTIONABLE => $query->abandonedTemporary(),
            self::QUICK_REVIEW => $query->needsManagementReview(),
            self::QUICK_ACTIVE => $query->activeTemporary(),
            default => $query,
        };
    }

    public function scopeOrderByManagementPriority(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $onlineCutoff = $at->copy()->subMinutes(max(1, $onlineMinutes));
        $inPersonCutoff = $at->copy()->subMinutes(max(1, $inPersonMinutes));
        $table = $this->getTable();
        $scope = "{$table}.reservation_scope";
        $lastSeen = "{$table}.last_seen_at";
        $expires = "{$table}.expires_at";
        $released = "{$table}.released_at";
        $releaseReason = "{$table}.release_reason";
        $converted = "{$table}.converted_at";
        $preinvoice = "{$table}.preinvoice_order_id";
        $quantity = "{$table}.quantity";
        $openSql = "{$preinvoice} IS NULL AND {$converted} IS NULL AND {$released} IS NULL AND {$releaseReason} IS NULL AND {$quantity} > 0 AND {$scope} IN (?, ?)";
        $abandonedSql = "(({$scope} = ? AND (({$expires} IS NOT NULL AND {$expires} <= ?) OR ({$lastSeen} IS NOT NULL AND {$lastSeen} <= ?))) OR ({$scope} = ? AND {$lastSeen} IS NOT NULL AND {$lastSeen} <= ?))";
        $reviewSql = "{$preinvoice} IS NULL AND {$converted} IS NULL AND {$released} IS NULL AND {$releaseReason} IS NULL AND {$quantity} > 0 AND ({$scope} IS NULL OR {$scope} NOT IN (?, ?))";
        $connectedSql = "{$released} IS NULL AND {$releaseReason} IS NULL AND ({$preinvoice} IS NOT NULL OR {$converted} IS NOT NULL)";

        return $query
            ->orderByRaw(
                "CASE WHEN ({$openSql}) AND ({$abandonedSql}) THEN 1 WHEN ({$reviewSql}) THEN 2 WHEN ({$openSql}) OR ({$connectedSql}) THEN 3 ELSE 4 END",
                [
                    self::SCOPE_TEMPORARY_ONLINE,
                    self::SCOPE_TEMPORARY_IN_PERSON,
                    self::SCOPE_TEMPORARY_ONLINE,
                    $at,
                    $onlineCutoff,
                    self::SCOPE_TEMPORARY_IN_PERSON,
                    $inPersonCutoff,
                    self::SCOPE_TEMPORARY_ONLINE,
                    self::SCOPE_TEMPORARY_IN_PERSON,
                    self::SCOPE_TEMPORARY_ONLINE,
                    self::SCOPE_TEMPORARY_IN_PERSON,
                ],
            )
            ->orderBy($this->qualifyColumn('created_at'))
            ->orderBy($this->qualifyColumn('id'));
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
                })
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"));
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

    /** @return array<int, string> */
    public static function managementQuickFilters(): array
    {
        return [self::QUICK_ACTIONABLE, self::QUICK_REVIEW, self::QUICK_ACTIVE];
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

    public function managementPriority(?CarbonInterface $at = null): int
    {
        if ($this->isActionableForManagement($at)) {
            return 1;
        }

        if ($this->needsManagementReview($at)) {
            return 2;
        }

        if ($this->isActiveTemporary($at) || $this->isConnectedToPreinvoice()) {
            return 3;
        }

        return 4;
    }

    public function isActionableForManagement(?CarbonInterface $at = null): bool
    {
        return $this->canBeManuallyReleased($at);
    }

    public function needsManagementReview(?CarbonInterface $at = null): bool
    {
        return $this->isAbandoned($at) || $this->managementStatus($at) === self::STATUS_UNKNOWN;
    }

    public function isOldForManagement(?CarbonInterface $at = null): bool
    {
        if ($this->released_at !== null || $this->created_at === null) {
            return false;
        }

        $at ??= now();

        return $this->created_at->lte($at->copy()->subMinutes(self::OLD_RESERVATION_MINUTES));
    }

    public function managementImportance(?CarbonInterface $at = null): string
    {
        if ($this->isActionableForManagement($at) && $this->isOldForManagement($at)) {
            return self::IMPORTANCE_CRITICAL;
        }

        if ($this->isActionableForManagement($at)) {
            return self::IMPORTANCE_HIGH;
        }

        if ($this->needsManagementReview($at)) {
            return self::IMPORTANCE_REVIEW;
        }

        return self::IMPORTANCE_NORMAL;
    }

    public function managementImportanceLabel(?CarbonInterface $at = null): string
    {
        return match ($this->managementImportance($at)) {
            self::IMPORTANCE_CRITICAL => 'فوری',
            self::IMPORTANCE_HIGH => 'زیاد',
            self::IMPORTANCE_REVIEW => 'نیازمند بررسی',
            default => 'عادی',
        };
    }

    public function managementAgeLabel(?CarbonInterface $at = null): string
    {
        if ($this->created_at === null) {
            return 'نامشخص';
        }

        $at ??= now();
        $minutes = max(0, (int) $this->created_at->diffInMinutes($at));

        return match (true) {
            $minutes < 1 => 'لحظاتی پیش',
            $minutes < 60 => $minutes.' دقیقه قبل',
            $minutes < 1440 => intdiv($minutes, 60).' ساعت قبل',
            default => intdiv($minutes, 1440).' روز قبل',
        };
    }

    public function managementWarning(?CarbonInterface $at = null): ?string
    {
        if (! $this->isOldForManagement($at)) {
            return null;
        }

        return $this->isActionableForManagement($at)
            ? 'رزرو قدیمی و قابل آزادسازی است.'
            : 'رزرو قدیمی است و باید بررسی شود.';
    }

    public function releaseReasonLabel(): string
    {
        return match ($this->release_reason) {
            'temporary_session_lost' => 'قطع فعالیت رزرو موقت',
            'temporary_online_expired' => 'پایان زمان رزرو آنلاین',
            'manual_release' => 'آزادسازی دستی',
            null, '' => 'ثبت نشده',
            default => $this->release_reason,
        };
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

    public function hasValidHeartbeat(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        if ($this->last_seen_at === null) {
            return false;
        }

        $at ??= now();
        $staleMinutes = $this->reservation_scope === self::SCOPE_TEMPORARY_IN_PERSON
            ? max(1, $inPersonMinutes)
            : max(1, $onlineMinutes);

        return $this->last_seen_at->gt($at->copy()->subMinutes($staleMinutes));
    }

    public function hasActiveRelatedDraft(): bool
    {
        return $this->relationLoaded('activeDrafts')
            ? $this->activeDrafts->isNotEmpty()
            : $this->activeDrafts()->exists();
    }

    public function isOrphaned(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        return $this->released_at === null
            && $this->preinvoice_order_id === null
            && $this->quantity > 0
            && ! $this->hasActiveRelatedDraft()
            && ! $this->hasValidHeartbeat($at, $onlineMinutes, $inPersonMinutes);
    }

    public function isCleanupCandidate(
        ?CarbonInterface $at = null,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): bool {
        return $this->released_at === null
            && $this->release_reason === null
            && $this->preinvoice_order_id === null
            && $this->converted_at === null
            && $this->quantity > 0
            && in_array($this->reservation_scope, [
                self::SCOPE_TEMPORARY_ONLINE,
                self::SCOPE_TEMPORARY_IN_PERSON,
            ], true)
            && ! $this->hasActiveRelatedDraft()
            && ! $this->hasValidHeartbeat($at, $onlineMinutes, $inPersonMinutes);
    }

    public function isConnectedToPreinvoice(): bool
    {
        return $this->released_at === null
            && $this->release_reason === null
            && ($this->preinvoice_order_id !== null || $this->converted_at !== null);
    }

    private function isOpenTemporary(): bool
    {
        return $this->preinvoice_order_id === null
            && $this->converted_at === null
            && $this->released_at === null
            && $this->release_reason === null
            && $this->quantity > 0
            && in_array($this->reservation_scope, [
                self::SCOPE_TEMPORARY_ONLINE,
                self::SCOPE_TEMPORARY_IN_PERSON,
            ], true);
    }
}
