<?php

use App\Services\Commissions\CommissionInvoiceSyncOutboxService;
use App\Services\Commissions\CommissionReturnSyncOutboxService;
use App\Services\Report\TelegramDailyReport;
use App\Services\Sync\InventoryProductsSyncService;
use App\Services\Sync\SiteCustomersSyncService;
use App\Services\Sync\SiteImageSyncService;
use App\Services\Sync\SitePaidOrdersSyncService;
use App\Services\Sync\SyncProductsToSite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reservations:cleanup')
    ->name('warehouse-reservation-cleanup')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->onFailure(function (): void {
        Log::error('WAREHOUSE_RESERVATION_CLEANUP_SCHEDULE_FAILED');
    });

Schedule::call(function () {
    app(SyncProductsToSite::class)->syncAll();
})
    ->everyMinute()
    ->name('inventory-products-service')
    ->withoutOverlapping(1);

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

// Immediate after-commit delivery is the normal path. This schedule is
// only the durable recovery path for crashes/transient sync failures.
Schedule::call(function () {
    app(CommissionInvoiceSyncOutboxService::class)->drain(100);
})
    ->name('commission-incremental-sync-outbox')
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(CommissionReturnSyncOutboxService::class)->drain(100);
})
    ->name('commission-return-sync-outbox')
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::call(function () {
    app(TelegramDailyReport::class)->send();
})
    ->name('telegram_daily_report')
    ->dailyAt('21:00')
    ->withoutOverlapping(10);
