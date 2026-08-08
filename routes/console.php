<?php

use App\Services\Report\TelegramDailyReport;
use App\Services\Sync\InventoryProductsSyncService;
use App\Services\Sync\SiteCustomersSyncService;
use App\Services\Sync\SiteImageSyncService;
use App\Services\Sync\SitePaidOrdersSyncService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(InventoryProductsSyncService::class)->syncAll();
})
    ->everyTenSeconds()
    ->name('inventory-products-Service')
    ->withoutOverlapping();

Schedule::call(function () {
    app(SiteImageSyncService::class)->syncAll();
})
    ->everyTenSeconds()
    ->name('site-image-products-sync')
    ->withoutOverlapping();

Schedule::call(function () {
    app(SiteCustomersSyncService::class)->syncAll();
})
    ->name('site-customers-sync')
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(SitePaidOrdersSyncService::class)->syncAll();
})
    ->name('site-paid-orders-sync')
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::command('crm:sync-users --incremental')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('crm:sync-users --full')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::call(function () {
    app(TelegramDailyReport::class)->send();
})
    ->name('telegram_daily_report')
    ->dailyAt('21:00')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping(10);
