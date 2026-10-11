<?php
use Illuminate\Console\Scheduling\{Schedule,CallbackEvent};
use Illuminate\Support\Facades\Artisan;
it('runs reservation expiry inside PHP without starting a subprocess', function () {
 $events=collect(app(Schedule::class)->events());
 foreach(['warehouse-reservation-cleanup'=>['*/10 * * * *','reservations:cleanup'],'preinvoice-official-reservation-expiry'=>['*/5 * * * *','preinvoices:expire-reservations']] as $name=>[$expression,$command]) {
  $event=$events->first(fn($e)=>$e->description===$name);
  expect($event)->toBeInstanceOf(CallbackEvent::class)->and($event->expression)->toBe($expression)->and($event->withoutOverlapping)->toBeTrue();
  Artisan::shouldReceive('call')->once()->with($command)->andReturn(0);
  $event->run(app());
  expect($event->exitCode)->toBe(0);
 }
});
it('marks an in-process reservation command failure as failed', function () {
 $event=collect(app(Schedule::class)->events())->first(fn($e)=>$e->description==='warehouse-reservation-cleanup');
 Artisan::shouldReceive('call')->once()->with('reservations:cleanup')->andReturn(1);
 expect(fn()=>$event->run(app()))->toThrow(RuntimeException::class);
});
