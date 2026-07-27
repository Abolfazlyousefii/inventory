<?php

use App\Http\Controllers\External\RegisterStoreCustomerController;
use Illuminate\Support\Facades\Route;

Route::post('/external/users', RegisterStoreCustomerController::class)
    ->name('api.external.users.store');
