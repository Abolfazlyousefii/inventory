<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules the official preinvoice reservation expiry command', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'preinvoices:expire-reservations'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});
