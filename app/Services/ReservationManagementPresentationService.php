<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use Carbon\CarbonInterface;

/** Maps canonical reservation classification to management-only display metadata. */
class ReservationManagementPresentationService
{
    public const BUCKET_CURRENT = 'current';
    public const BUCKET_ACTIONABLE = 'actionable';
    public const BUCKET_REVIEW = 'review';
    public const BUCKET_HISTORY = 'history';

    public function __construct(private readonly ReservationClassificationService $classification)
    {
    }

    /**
     * @return array{
     *   classification:array,
     *   bucket:string,
     *   label:string,
     *   badge:string,
     *   warning:?string,
     *   priority:int,
     *   can_release:bool,
     *   can_archive:bool
     * }
     */
    public function present(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array
    {
        return $this->presentClassification($this->classification->classify($reservation, $at));
    }

    /** @param array{state:string} $classification */
    public function presentClassification(array $classification): array
    {
        $state = $classification['state'];

        [$bucket, $label, $badge, $warning, $priority] = match ($state) {
            ReservationClassificationService::STATE_ACTIVE_VALID => [
                self::BUCKET_CURRENT,
                'فعال و محافظت‌شده',
                'status-active',
                'این رزرو در اختیار پیش‌نویس فعال است و قابل آزادسازی نیست.',
                3,
            ],
            ReservationClassificationService::STATE_TEMPORARY_ACTIVE => [
                self::BUCKET_CURRENT,
                'رزرو موقت فعال',
                'status-active',
                null,
                3,
            ],
            ReservationClassificationService::STATE_OFFICIAL_ACTIVE => [
                self::BUCKET_CURRENT,
                'رزرو رسمی فعال',
                'status-preinvoice',
                null,
                3,
            ],
            ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE => [
                self::BUCKET_ACTIONABLE,
                'قابل آزادسازی',
                'status-releasable',
                'رزرو موقت منقضی و تنها مورد مجاز برای آزادسازی عادی موجودی است.',
                1,
            ],
            ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS => [
                self::BUCKET_REVIEW,
                'ابهام تاریخی — بررسی/بایگانی',
                'status-review',
                'این ردیف فقط برای بررسی یا بایگانی تاریخیِ بدون تغییر موجودی است.',
                2,
            ],
            ReservationClassificationService::STATE_INVALID_OFFICIAL => [
                self::BUCKET_REVIEW,
                'رزرو رسمی نامعتبر — نیازمند بررسی',
                'status-critical',
                'وضعیت سند رسمی با نگهداری رزرو سازگار نیست.',
                2,
            ],
            ReservationClassificationService::STATE_LEGACY_SAFE => [
                self::BUCKET_REVIEW,
                'Legacy قابل پاکسازی کنترل‌شده',
                'status-review',
                'این ردیف فقط از مسیر پاکسازی Legacy بررسی می‌شود.',
                2,
            ],
            ReservationClassificationService::STATE_RELEASED => [
                self::BUCKET_HISTORY,
                'آزادشده',
                'status-neutral',
                null,
                4,
            ],
            ReservationClassificationService::STATE_CONSUMED => [
                self::BUCKET_HISTORY,
                'مصرف‌شده',
                'status-neutral',
                null,
                4,
            ],
            ReservationClassificationService::STATE_INVOICE_LINKED => [
                self::BUCKET_HISTORY,
                'متصل به فاکتور',
                'status-neutral',
                null,
                4,
            ],
            default => [
                self::BUCKET_REVIEW,
                'وضعیت ناشناخته — نیازمند بررسی',
                'status-review',
                'وضعیت canonical ناشناخته است؛ هیچ عملیات خودکاری مجاز نیست.',
                2,
            ],
        };

        return [
            'classification' => $classification,
            'bucket' => $bucket,
            'label' => $label,
            'badge' => $badge,
            'warning' => $warning,
            'priority' => $priority,
            'can_release' => $state === ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE,
            'can_archive' => $state === ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS,
        ];
    }
}
