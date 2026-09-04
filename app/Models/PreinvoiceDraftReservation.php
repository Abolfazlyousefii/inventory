<?php

namespace App\Models;

use App\Services\ReservationClassificationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class PreinvoiceDraftReservation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_RELEASABLE = 'releasable';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_PREINVOICE_ACTIVE = 'preinvoice_active';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_CRITICAL = 'critical';

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

    public const PREINVOICE_REVIEW_AFTER_HOURS = 24;

    public const PREINVOICE_CRITICAL_AFTER_HOURS = 72;

    public const LEGACY_STALE_HOURS = 72;

    public const SCOPE_TEMPORARY_ONLINE = 'temporary_online';

    public const SCOPE_TEMPORARY_IN_PERSON = 'temporary_in_person';

    public const SCOPE_OFFICIAL = 'official';

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
        return $query->cleanupCandidates($onlineMinutes, $inPersonMinutes, $at);
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
            ->where($this->qualifyColumn('quantity'), '>', 0);
    }

    public function scopeActiveForReservedCache(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $query) use ($at): void {
            $query->activeTemporary(
                self::DEFAULT_ONLINE_STALE_MINUTES,
                self::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )->orWhere(function (Builder $query): void {
                $query->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
                    ->whereNull($this->qualifyColumn('converted_at'))
                    ->whereNull($this->qualifyColumn('released_at'))
                    ->whereNull($this->qualifyColumn('release_reason'))
                    ->where($this->qualifyColumn('quantity'), '>', 0)
                    ->whereHas('order', fn (Builder $order) => $order->whereIn('status', PreinvoiceOrder::reservationHoldingStatuses()));

                if (Schema::hasTable('invoices')) {
                    $query->whereDoesntHave('order.invoice');
                }
            });
        });
    }

    public function scopeLegacyCleanupCandidates(
        Builder $query,
        int $staleHours = self::LEGACY_STALE_HOURS,
        ?CarbonInterface $at = null,
    ): Builder {
        $at ??= now();
        $cutoff = $at->copy()->subHours(max(1, $staleHours));
        $lastActivity = 'COALESCE('.$this->qualifyColumn('last_seen_at').', '.$this->qualifyColumn('created_at').')';

        return $query
            ->whereNull($this->qualifyColumn('released_at'))
            ->whereNull($this->qualifyColumn('release_reason'))
            ->where($this->qualifyColumn('quantity'), '>', 0)
            ->whereRaw("{$lastActivity} <= ?", [$cutoff])
            ->where(function (Builder $query): void {
                $query->where(function (Builder $temporary): void {
                    $temporary->whereNull($this->qualifyColumn('preinvoice_order_id'));

                    if (Schema::hasColumn('preinvoice_orders', 'draft_token')) {
                        $temporary->whereDoesntHave('activeDrafts');
                    }
                })->orWhere(function (Builder $official): void {
                    $official->whereNotNull($this->qualifyColumn('preinvoice_order_id'));

                    if (Schema::hasTable('invoices')) {
                        $official->whereDoesntHave('order.invoice');
                    }

                    $official->where(function (Builder $inactive): void {
                            $inactive->whereDoesntHave('order')
                                ->orWhereHas('order', fn (Builder $order) => $order->whereNotIn('status', PreinvoiceOrder::reservationHoldingStatuses()));
                        });
                });
            });
    }

    public function legacyCleanupReason(): string
    {
        if ($this->preinvoice_order_id === null) {
            return 'legacy_without_preinvoice';
        }

        if ($this->order === null) {
            return 'legacy_missing_preinvoice';
        }

        return 'legacy_inactive_preinvoice';
    }

    public function scopeConvertedUnreleased(Builder $query): Builder
    {
        return $query
            ->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
            ->whereNotNull($this->qualifyColumn('converted_at'))
            ->whereNull($this->qualifyColumn('released_at'));
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
        $lastActivity = 'COALESCE('.$this->qualifyColumn('last_seen_at').', '.$this->qualifyColumn('created_at').')';

        return $query->openTemporary()->where(function (Builder $query) use ($at, $onlineCutoff, $inPersonCutoff, $lastActivity): void {
            $query->where(function (Builder $query) use ($inPersonCutoff, $lastActivity): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                    ->whereRaw("{$lastActivity} <= ?", [$inPersonCutoff]);
            })->orWhere(function (Builder $query) use ($at, $onlineCutoff, $lastActivity): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull($this->qualifyColumn('reservation_scope'))
                        ->orWhere($this->qualifyColumn('reservation_scope'), '!=', self::SCOPE_TEMPORARY_IN_PERSON);
                })->where(function (Builder $query) use ($at, $onlineCutoff, $lastActivity): void {
                    $query->where($this->qualifyColumn('expires_at'), '<=', $at)
                        ->orWhereRaw("{$lastActivity} <= ?", [$onlineCutoff]);
                });
            });
        });
    }

    public function scopeCleanupCandidates(
        Builder $query,
        int $onlineMinutes = self::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = self::DEFAULT_IN_PERSON_STALE_MINUTES,
        ?CarbonInterface $at = null,
    ): Builder {
        return $query
            ->abandonedTemporary($onlineMinutes, $inPersonMinutes, $at)
            ->whereDoesntHave('activeDrafts')
            ->whereDoesntHave('order.invoice');
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
        $lastActivity = 'COALESCE('.$this->qualifyColumn('last_seen_at').', '.$this->qualifyColumn('created_at').')';

        return $query->openTemporary()->whereNotNull($this->qualifyColumn('last_seen_at'))->where(function (Builder $query) use ($at, $onlineCutoff, $inPersonCutoff, $lastActivity): void {
            $query->where(function (Builder $query) use ($inPersonCutoff, $lastActivity): void {
                $query->where($this->qualifyColumn('reservation_scope'), self::SCOPE_TEMPORARY_IN_PERSON)
                    ->whereRaw("{$lastActivity} > ?", [$inPersonCutoff]);
            })->orWhere(function (Builder $query) use ($at, $onlineCutoff, $lastActivity): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull($this->qualifyColumn('reservation_scope'))
                        ->orWhere($this->qualifyColumn('reservation_scope'), '!=', self::SCOPE_TEMPORARY_IN_PERSON);
                })
                    ->where(function (Builder $query) use ($at): void {
                        $query->whereNull($this->qualifyColumn('expires_at'))
                            ->orWhere($this->qualifyColumn('expires_at'), '>', $at);
                    })
                    ->whereRaw("{$lastActivity} > ?", [$onlineCutoff]);
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

    public function scopePreinvoiceWithoutInvoice(Builder $query): Builder
    {
        return $query
            ->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
            ->whereHas('order')
            ->whereDoesntHave('order.invoice');
    }

    public function scopeVisibleInWarehouseManagement(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('released_at'))
            ->whereNull($this->qualifyColumn('release_reason'))
            ->where($this->qualifyColumn('quantity'), '>', 0)
            ->whereDoesntHave('order.invoice')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull($this->qualifyColumn('preinvoice_order_id'))
                        ->whereNull($this->qualifyColumn('converted_at'));
                })->orWhere(fn (Builder $query) => $query->preinvoiceWithoutInvoice());
            });
    }

    public function scopeBusinessActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $query) use ($at): void {
            $query->activeTemporary(
                self::DEFAULT_ONLINE_STALE_MINUTES,
                self::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )->orWhere(function (Builder $query) use ($at): void {
                $query->preinvoiceWithoutInvoice()
                    ->where($this->qualifyColumn('created_at'), '>', $at->copy()->subHours(self::PREINVOICE_REVIEW_AFTER_HOURS));
            });
        });
    }

    public function scopeBusinessNeedsReview(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $query) use ($at): void {
            $query->abandonedTemporary(
                self::DEFAULT_ONLINE_STALE_MINUTES,
                self::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )->orWhere(function (Builder $query) use ($at): void {
                $query->preinvoiceWithoutInvoice()
                    ->where($this->qualifyColumn('created_at'), '<=', $at->copy()->subHours(self::PREINVOICE_REVIEW_AFTER_HOURS))
                    ->where($this->qualifyColumn('created_at'), '>=', $at->copy()->subHours(self::PREINVOICE_CRITICAL_AFTER_HOURS));
            });
        });
    }

    public function scopeNeedsBusinessAttention(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $query) use ($at): void {
            $query->businessNeedsReview($at)
                ->orWhere(fn (Builder $query) => $query->criticalPreinvoice($at));
        });
    }

    public function scopeCriticalPreinvoice(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->preinvoiceWithoutInvoice()
            ->where($this->qualifyColumn('created_at'), '<', $at->copy()->subHours(self::PREINVOICE_CRITICAL_AFTER_HOURS));
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
            self::QUICK_REVIEW => $query->needsBusinessAttention(),
            self::QUICK_ACTIVE => $query->businessActive(),
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
        $created = "{$table}.created_at";
        $openSql = "{$preinvoice} IS NULL AND {$converted} IS NULL AND {$released} IS NULL AND {$releaseReason} IS NULL AND {$quantity} > 0 AND {$scope} IN (?, ?)";
        $abandonedSql = "(({$scope} = ? AND (({$expires} IS NOT NULL AND {$expires} <= ?) OR ({$lastSeen} IS NOT NULL AND {$lastSeen} <= ?))) OR ({$scope} = ? AND {$lastSeen} IS NOT NULL AND {$lastSeen} <= ?))";
        $reviewSql = "{$preinvoice} IS NULL AND {$converted} IS NULL AND {$released} IS NULL AND {$releaseReason} IS NULL AND {$quantity} > 0 AND ({$scope} IS NULL OR {$scope} NOT IN (?, ?))";
        $oldPreinvoiceSql = "{$preinvoice} IS NOT NULL AND {$created} <= ?";
        $connectedSql = "{$released} IS NULL AND {$releaseReason} IS NULL AND ({$preinvoice} IS NOT NULL OR {$converted} IS NOT NULL)";

        return $query
            ->orderByRaw(
                "CASE WHEN ({$openSql}) AND ({$abandonedSql}) THEN 1 WHEN ({$reviewSql}) OR ({$oldPreinvoiceSql}) THEN 2 WHEN ({$openSql}) OR ({$connectedSql}) THEN 3 ELSE 4 END",
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
                    $at->copy()->subHours(self::PREINVOICE_REVIEW_AFTER_HOURS),
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
            self::STATUS_CONNECTED => $query->preinvoiceWithoutInvoice(),
            self::STATUS_PREINVOICE_ACTIVE => $query->preinvoiceWithoutInvoice()
                ->where($this->qualifyColumn('created_at'), '>', now()->subHours(self::PREINVOICE_REVIEW_AFTER_HOURS)),
            self::STATUS_NEEDS_REVIEW => $query->businessNeedsReview(),
            self::STATUS_CRITICAL => $query->criticalPreinvoice(),
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
                        ->orWhere('variety_code', 'like', "%{$search}%")
                        ->orWhere('variant_name', 'like', "%{$search}%")
                        ->orWhere('variety_name', 'like', "%{$search}%");
                })
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"))
                ->orWhereHas('order', function (Builder $order) use ($search): void {
                    $order->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_mobile', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Age filter: reservations created at or before ($at - $hours). Reuses
     * the same hour-based comparison already used by the health thresholds
     * (PREINVOICE_REVIEW_AFTER_HOURS / PREINVOICE_CRITICAL_AFTER_HOURS) —
     * just parameterized instead of fixed at 24/72. The UI's "older than 30
     * days" preset is passed in as 720 hours by the caller; there is no
     * separate day-based rule.
     */
    public function scopeOlderThanHours(Builder $query, ?int $hours, ?CarbonInterface $at = null): Builder
    {
        if ($hours === null) {
            return $query;
        }

        $at ??= now();

        return $query->where($this->qualifyColumn('created_at'), '<=', $at->copy()->subHours(max(0, $hours)));
    }

    /**
     * Lifecycle filter: active / released / consumed only (no "converted" as
     * a separate state — matches ReservationClassificationService::classifyLifecycle()
     * exactly: released_at set wins first, then converted_at set, else active).
     */
    public function scopeForLifecycle(Builder $query, ?string $lifecycle): Builder
    {
        return match ($lifecycle) {
            ReservationClassificationService::LIFECYCLE_RELEASED => $query->whereNotNull($this->qualifyColumn('released_at')),
            ReservationClassificationService::LIFECYCLE_CONSUMED => $query
                ->whereNull($this->qualifyColumn('released_at'))
                ->whereNotNull($this->qualifyColumn('converted_at')),
            ReservationClassificationService::LIFECYCLE_ACTIVE => $query
                ->whereNull($this->qualifyColumn('released_at'))
                ->whereNull($this->qualifyColumn('converted_at')),
            default => $query,
        };
    }

    public function scopeForCreator(Builder $query, ?int $userId): Builder
    {
        return $userId === null ? $query : $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function scopeForProduct(Builder $query, ?int $productId): Builder
    {
        return $productId === null ? $query : $query->where($this->qualifyColumn('product_id'), $productId);
    }

    public function scopeForVariant(Builder $query, ?int $variantId): Builder
    {
        return $variantId === null ? $query : $query->where($this->qualifyColumn('variant_id'), $variantId);
    }

    /**
     * Customer filter, reached only through the related preinvoice order
     * (PreinvoiceOrder::customer_id / customer_name / customer_mobile).
     * Temporary reservations have no preinvoice_order_id and therefore no
     * order to match — whereHas('order', ...) naturally excludes them. This
     * is intentional, not a bug: a reservation with no preinvoice has no
     * customer to filter by.
     */
    public function scopeForCustomer(Builder $query, ?int $customerId, ?string $customerSearch = null): Builder
    {
        $customerSearch = trim((string) $customerSearch);
        if ($customerId === null && $customerSearch === '') {
            return $query;
        }

        return $query->whereHas('order', function (Builder $order) use ($customerId, $customerSearch): void {
            if ($customerId !== null) {
                $order->where('customer_id', $customerId);
            }

            if ($customerSearch !== '') {
                $order->where(function (Builder $order) use ($customerSearch): void {
                    $order->where('customer_name', 'like', "%{$customerSearch}%")
                        ->orWhere('customer_mobile', 'like', "%{$customerSearch}%");
                });
            }
        });
    }

    /**
     * Classification/management-label filter. This is a SQL mirror of
     * ReservationClassificationService::classifyManagementLabel() and MUST
     * be kept in the same precedence order as that method:
     *   1. Consumed          (converted_at set, not released)
     *   2. Legacy Candidate  (scopeLegacyCleanupCandidates)
     *   3. Critical          (scopeCriticalPreinvoice, i.e. businessStatus() === STATUS_CRITICAL)
     *   4. Official Preinvoice (has a preinvoice_order_id)
     *   5. Temporary Orphan  (scopeCleanupCandidates — the 5/15-minute
     *      heartbeat definition also used by the "orphaned" tab, matching
     *      the model's isOrphaned()/isCleanupCandidate() instance methods)
     *   6. Temporary Active  (fallback — everything else)
     * If ReservationClassificationService::classifyManagementLabel() ever
     * changes its precedence or predicates, this scope must change with it.
     */
    public function scopeManagementLabel(Builder $query, ?string $label, ?CarbonInterface $at = null): Builder
    {
        if ($label === null || $label === '') {
            return $query;
        }

        $at ??= now();
        $notReleasedOrConverted = fn (Builder $query): Builder => $query
            ->whereNull($this->qualifyColumn('released_at'))
            ->whereNull($this->qualifyColumn('converted_at'));

        return match ($label) {
            ReservationClassificationService::LABEL_CONSUMED => $query
                ->whereNull($this->qualifyColumn('released_at'))
                ->whereNotNull($this->qualifyColumn('converted_at')),

            ReservationClassificationService::LABEL_LEGACY_CANDIDATE => $query
                ->legacyCleanupCandidates(self::LEGACY_STALE_HOURS, $at),

            ReservationClassificationService::LABEL_CRITICAL => $query
                ->where($notReleasedOrConverted)
                ->whereNot(fn (Builder $q) => $q->legacyCleanupCandidates(self::LEGACY_STALE_HOURS, $at))
                ->criticalPreinvoice($at),

            ReservationClassificationService::LABEL_OFFICIAL_PREINVOICE => $query
                ->where($notReleasedOrConverted)
                ->whereNotNull($this->qualifyColumn('preinvoice_order_id'))
                ->whereNot(fn (Builder $q) => $q->legacyCleanupCandidates(self::LEGACY_STALE_HOURS, $at))
                ->whereNot(fn (Builder $q) => $q->criticalPreinvoice($at)),

            ReservationClassificationService::LABEL_TEMPORARY_ORPHAN => $query
                ->where($notReleasedOrConverted)
                ->whereNull($this->qualifyColumn('preinvoice_order_id'))
                ->whereNot(fn (Builder $q) => $q->legacyCleanupCandidates(self::LEGACY_STALE_HOURS, $at))
                ->cleanupCandidates(self::DEFAULT_ONLINE_STALE_MINUTES, self::DEFAULT_IN_PERSON_STALE_MINUTES, $at),

            ReservationClassificationService::LABEL_TEMPORARY_ACTIVE => $query
                ->where($notReleasedOrConverted)
                ->whereNull($this->qualifyColumn('preinvoice_order_id'))
                ->whereNot(fn (Builder $q) => $q->legacyCleanupCandidates(self::LEGACY_STALE_HOURS, $at))
                ->whereNot(fn (Builder $q) => $q->cleanupCandidates(self::DEFAULT_ONLINE_STALE_MINUTES, self::DEFAULT_IN_PERSON_STALE_MINUTES, $at)),

            default => $query,
        };
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
            self::STATUS_PREINVOICE_ACTIVE,
            self::STATUS_NEEDS_REVIEW,
            self::STATUS_CRITICAL,
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

    public function businessStatus(?CarbonInterface $at = null): string
    {
        $at ??= now();

        if ($this->isPreinvoiceReservationWithoutInvoice()) {
            if ($this->created_at?->lt($at->copy()->subHours(self::PREINVOICE_CRITICAL_AFTER_HOURS))) {
                return self::STATUS_CRITICAL;
            }

            if ($this->created_at?->lte($at->copy()->subHours(self::PREINVOICE_REVIEW_AFTER_HOURS))) {
                return self::STATUS_NEEDS_REVIEW;
            }

            return self::STATUS_PREINVOICE_ACTIVE;
        }

        if ($this->isTemporaryReservation()) {
            return $this->isAbandoned($at)
                ? self::STATUS_NEEDS_REVIEW
                : self::STATUS_ACTIVE;
        }

        return self::STATUS_UNKNOWN;
    }

    public function businessStatusLabel(?CarbonInterface $at = null): string
    {
        return match ($this->businessStatus($at)) {
            self::STATUS_ACTIVE => 'فعال',
            self::STATUS_PREINVOICE_ACTIVE => 'پیش‌فاکتور فعال',
            self::STATUS_NEEDS_REVIEW => 'نیاز بررسی',
            self::STATUS_CRITICAL => 'بحرانی',
            default => 'نامشخص',
        };
    }

    public function businessDisplayReason(?CarbonInterface $at = null): string
    {
        return match ($this->businessStatus($at)) {
            self::STATUS_ACTIVE => 'در حال ثبت پیش‌فاکتور',
            self::STATUS_PREINVOICE_ACTIVE => 'متصل به پیش‌فاکتور شماره '.($this->order?->uuid ?? $this->preinvoice_order_id),
            self::STATUS_NEEDS_REVIEW => $this->isPreinvoiceReservationWithoutInvoice()
                ? 'پیش‌فاکتور بدون فاکتور بیش از 24 ساعت'
                : 'رزرو موقت رهاشده و قابل پاک‌سازی',
            self::STATUS_CRITICAL => 'پیش‌فاکتور بدون فاکتور بیش از 72 ساعت',
            default => 'وضعیت مالک رزرو مشخص نیست',
        };
    }

    public function preinvoiceAgeHours(?CarbonInterface $at = null): int
    {
        if ($this->created_at === null) {
            return 0;
        }

        return max(0, (int) $this->created_at->diffInHours($at ?? now()));
    }

    public function managementLastActivityAt(): ?CarbonInterface
    {
        $lastActivity = $this->last_seen_at ?? $this->updated_at ?? $this->created_at;
        $orderUpdatedAt = $this->preinvoice_order_id !== null ? $this->order?->updated_at : null;

        if ($orderUpdatedAt !== null && ($lastActivity === null || $orderUpdatedAt->gt($lastActivity))) {
            return $orderUpdatedAt;
        }

        return $lastActivity;
    }

    public function preinvoiceConnectedAt(): ?CarbonInterface
    {
        if ($this->converted_at !== null || $this->preinvoice_order_id === null) {
            return $this->converted_at;
        }

        return $this->order?->created_at;
    }

    public function managementPriority(?CarbonInterface $at = null): int
    {
        if ($this->isActionableForManagement($at)) {
            return 1;
        }

        if (in_array($this->businessStatus($at), [self::STATUS_NEEDS_REVIEW, self::STATUS_CRITICAL], true)
            || $this->needsManagementReview($at)) {
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
        return $this->isAbandoned($at)
            || in_array($this->businessStatus($at), [self::STATUS_NEEDS_REVIEW, self::STATUS_CRITICAL], true)
            || $this->managementStatus($at) === self::STATUS_UNKNOWN;
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
        if ($this->businessStatus($at) === self::STATUS_CRITICAL) {
            return self::IMPORTANCE_CRITICAL;
        }

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
        if ($this->businessStatus($at) === self::STATUS_CRITICAL) {
            return 'پیش‌فاکتور بدون فاکتور بیش از 72 ساعت و نیازمند بررسی فوری است.';
        }

        if ($this->businessStatus($at) === self::STATUS_NEEDS_REVIEW && $this->isPreinvoiceReservationWithoutInvoice()) {
            return 'پیش‌فاکتور بدون فاکتور بیش از 24 ساعت است.';
        }

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
        $lastActivity = $this->last_seen_at ?? $this->created_at;

        if ($lastActivity === null) {
            return false;
        }

        if ($this->reservation_scope !== self::SCOPE_TEMPORARY_IN_PERSON) {
            return ($this->expires_at?->lte($at) ?? false)
                || $lastActivity->lte($at->copy()->subMinutes(max(1, $onlineMinutes)));
        }

        return $lastActivity->lte($at->copy()->subMinutes(max(1, $inPersonMinutes)));
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
        return $this->isCleanupCandidate($at, $onlineMinutes, $inPersonMinutes);
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
            && ! $this->hasActiveRelatedDraft()
            && $this->isAbandoned($at, $onlineMinutes, $inPersonMinutes);
    }

    public function isConnectedToPreinvoice(): bool
    {
        return $this->released_at === null
            && $this->release_reason === null
            && ($this->preinvoice_order_id !== null || $this->converted_at !== null);
    }

    public function isTemporaryReservation(): bool
    {
        return $this->preinvoice_order_id === null
            && $this->converted_at === null
            && $this->released_at === null
            && $this->release_reason === null
            && $this->quantity > 0;
    }

    public function isPreinvoiceReservationWithoutInvoice(): bool
    {
        if ($this->preinvoice_order_id === null
            || $this->released_at !== null
            || $this->release_reason !== null
            || $this->quantity <= 0) {
            return false;
        }

        $order = $this->relationLoaded('order')
            ? $this->getRelation('order')
            : $this->order()->with('invoice:id,preinvoice_order_id')->first();

        if ($order === null) {
            return false;
        }

        return $order->relationLoaded('invoice')
            ? $order->invoice === null
            : ! $order->invoice()->exists();
    }

    private function isOpenTemporary(): bool
    {
        return $this->preinvoice_order_id === null
            && $this->converted_at === null
            && $this->released_at === null
            && $this->release_reason === null
            && $this->quantity > 0;
    }
}
