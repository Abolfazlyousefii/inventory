<?php

use App\Http\Controllers\PreinvoiceController;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AriyajanebiSyncService;
use App\Services\DefaultProductDesignService;
use App\Services\InventorySyncService;
use App\Services\InventoryWebhookService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(InventorySyncService::class)->syncAll();
})
    ->everyMinute();

Schedule::command('crm:sync-users --incremental')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('crm:sync-users --full')
    ->dailyAt('02:00')
    ->withoutOverlapping(180);
