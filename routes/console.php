<?php

use App\Services\Sync\InventoryProductsSyncService;
use App\Services\Sync\SiteCustomersSyncService;
use App\Services\Sync\SiteImageSyncService;
use App\Services\Sync\SitePaidOrdersSyncService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(InventoryProductsSyncService::class)->syncAll();
})
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(SiteImageSyncService::class)->syncAll();
})
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(SiteCustomersSyncService::class)->syncAll();
})
    ->name('site-customers-sync')
    ->everyFiveSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(SitePaidOrdersSyncService::class)->syncAll();
})
    ->name('site-paid-orders-sync')
    ->everyFiveSeconds()
    ->withoutOverlapping();

Schedule::command('crm:sync-users --incremental')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('crm:sync-users --full')
    ->dailyAt('02:00')
    ->withoutOverlapping();
