<?php

use App\Http\Controllers\External\RegisterPaidStoreOrderController;
use App\Http\Controllers\External\RegisterStoreCustomerController;
use Illuminate\Support\Facades\Route;

Route::post('/external/users', RegisterStoreCustomerController::class)
    ->name('api.external.users.store');

Route::post('/external/orders', RegisterPaidStoreOrderController::class)
    ->name('api.external.orders.store');
