<?php

use App\Http\Controllers\External\RegisterPaidStoreOrderController;
use App\Http\Controllers\External\RegisterStoreCustomerController;
use Illuminate\Support\Facades\Route;

Route::post('/external/users', RegisterStoreCustomerController::class)
    ->name('api.external.users.store');

Route::post('/external/orders', RegisterPaidStoreOrderController::class)
    ->name('api.external.orders.store');

// Server-to-server checkout reservations. They are not public browser endpoints.
Route::post('/external/stock-holds', [\App\Http\Controllers\External\SiteStockHoldController::class, 'reserve']);
Route::get('/external/stock-holds/{siteOrderId}', [\App\Http\Controllers\External\SiteStockHoldController::class, 'show'])->whereNumber('siteOrderId');
Route::post('/external/stock-holds/{siteOrderId}/confirm', [\App\Http\Controllers\External\SiteStockHoldController::class, 'confirm'])->whereNumber('siteOrderId');
Route::post('/external/stock-holds/{siteOrderId}/release', [\App\Http\Controllers\External\SiteStockHoldController::class, 'release'])->whereNumber('siteOrderId');
