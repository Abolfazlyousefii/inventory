<?php

use App\Services\Sync\InventoryProductsSyncService;
use App\Services\Sync\SiteImageSyncService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(InventoryProductsSyncService::class)->syncAll();
})
    ->everyTenSeconds();

Schedule::call(function () {
    app(SiteImageSyncService::class)->syncAll();
})
    ->everyTenSeconds();


Schedule::command('crm:sync-users --incremental')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('crm:sync-users --full')
    ->dailyAt('02:00')
    ->withoutOverlapping(180);
