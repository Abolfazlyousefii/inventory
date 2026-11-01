<?php

/**
 * INV-03: regression contract for the reservation-aware finance editor.
 * These source-contract checks supplement, not replace, behavioral tests.
 */

it('uses the reservation side effects transaction for the entire finance edit', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/FinancePreinvoiceEditorService.php');

    expect($source)->toContain('return ReservationSideEffects::transaction(function () use ($order, $data, $actor) {')
        ->not->toContain('return DB::transaction(function () use ($order, $data, $actor) {')
        ->toContain('$this->reservationService->adjustOfficialReservationDelta($lockedOrder, $item, $delta, $actor);');
});

it('validates locked expiry, document version and active official reservation expiry before writes', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/FinancePreinvoiceEditorService.php');
    $freezeExpiry = strpos($source, '$lockedOrder->stock_frozen_until?->lte(now())');
    $version = strpos($source, 'FinanceDocumentVersion::assertCurrent(');
    $activeExpiry = strpos($source, '$hasExpiredOfficialReservation = PreinvoiceDraftReservation::query()');
    $delta = strpos($source, '$this->reservationService->adjustOfficialReservationDelta(');

    expect($freezeExpiry)->not->toBeFalse()
        ->and($version)->not->toBeFalse()
        ->and($activeExpiry)->not->toBeFalse()
        ->and($delta)->not->toBeFalse()
        ->and($source)->toContain("->where('reservation_scope', PreinvoiceDraftReservation::SCOPE_OFFICIAL)")
        ->toContain("->whereNull('released_at')")
        ->toContain("->where('expires_at', '<=', now())");

    expect($freezeExpiry)->toBeLessThan($delta)
        ->and($version)->toBeLessThan($delta)
        ->and($activeExpiry)->toBeLessThan($delta);
});

it('does not apply finance finalization prerequisites to ordinary edits', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/FinancePreinvoiceEditorService.php');

    expect($source)->not->toContain('->assertFinanceApprovable(');
});
