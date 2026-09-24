<?php

namespace Tests\Feature;

use App\Services\ReservationClassificationService;
use App\Services\ReservationManagementPresentationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationManagementPresentationTest extends TestCase
{
    #[DataProvider('canonicalStateMatrix')]
    public function test_every_canonical_state_has_one_management_bucket_and_explicit_actions(
        string $state,
        string $bucket,
        bool $canRelease,
        bool $canArchive,
    ): void {
        $presentation = app(ReservationManagementPresentationService::class)
            ->presentClassification($this->classification($state));

        $this->assertSame($bucket, $presentation['bucket']);
        $this->assertSame($canRelease, $presentation['can_release']);
        $this->assertSame($canArchive, $presentation['can_archive']);
        $this->assertSame($state, $presentation['classification']['state']);
    }

    public function test_active_valid_is_protected_and_never_described_as_releasable(): void
    {
        $presentation = app(ReservationManagementPresentationService::class)
            ->presentClassification($this->classification(ReservationClassificationService::STATE_ACTIVE_VALID));

        $this->assertSame(ReservationManagementPresentationService::BUCKET_CURRENT, $presentation['bucket']);
        $this->assertFalse($presentation['can_release']);
        $this->assertFalse($presentation['can_archive']);
        $this->assertStringContainsString('محافظت', $presentation['label']);
        $this->assertStringNotContainsString('آزادسازی', $presentation['label']);
        $this->assertStringNotContainsString('پاکسازی', $presentation['label']);
    }

    public function test_historical_ambiguous_is_archive_review_only(): void
    {
        $presentation = app(ReservationManagementPresentationService::class)
            ->presentClassification($this->classification(ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS));

        $this->assertSame(ReservationManagementPresentationService::BUCKET_REVIEW, $presentation['bucket']);
        $this->assertFalse($presentation['can_release']);
        $this->assertTrue($presentation['can_archive']);
        $this->assertStringContainsString('بایگانی', $presentation['warning']);
    }

    public function test_unknown_future_state_fails_closed_into_review(): void
    {
        $presentation = app(ReservationManagementPresentationService::class)
            ->presentClassification($this->classification('future_state'));

        $this->assertSame(ReservationManagementPresentationService::BUCKET_REVIEW, $presentation['bucket']);
        $this->assertFalse($presentation['can_release']);
        $this->assertFalse($presentation['can_archive']);
    }

    public static function canonicalStateMatrix(): array
    {
        return [
            'active valid' => [ReservationClassificationService::STATE_ACTIVE_VALID, 'current', false, false],
            'temporary active' => [ReservationClassificationService::STATE_TEMPORARY_ACTIVE, 'current', false, false],
            'official active' => [ReservationClassificationService::STATE_OFFICIAL_ACTIVE, 'current', false, false],
            'stale releasable' => [ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE, 'actionable', true, false],
            'historical ambiguous' => [ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS, 'review', false, true],
            'invalid official' => [ReservationClassificationService::STATE_INVALID_OFFICIAL, 'review', false, false],
            'legacy safe' => [ReservationClassificationService::STATE_LEGACY_SAFE, 'review', false, false],
            'released' => [ReservationClassificationService::STATE_RELEASED, 'history', false, false],
            'consumed' => [ReservationClassificationService::STATE_CONSUMED, 'history', false, false],
            'invoice linked' => [ReservationClassificationService::STATE_INVOICE_LINKED, 'history', false, false],
        ];
    }

    private function classification(string $state): array
    {
        return [
            'state' => $state,
            'reason' => 'test_reason',
            'recommended_action' => 'test_action',
            'would_change_warehouse_stock' => false,
            'type' => ReservationClassificationService::TYPE_TEMPORARY,
            'lifecycle' => ReservationClassificationService::LIFECYCLE_ACTIVE,
            'health' => ReservationClassificationService::HEALTH_HEALTHY,
            'label' => $state,
        ];
    }
}
