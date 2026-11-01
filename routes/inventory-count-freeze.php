<?php

use App\Http\Controllers\InventoryCountFreezeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('admin/inventory-count-freeze')->name('inventory-count-freeze.')->group(function () {
    Route::get('/', [InventoryCountFreezeController::class, 'index'])->name('index');
    Route::post('/preview', [InventoryCountFreezeController::class, 'preview'])->name('preview');
    Route::post('/', [InventoryCountFreezeController::class, 'update'])->name('update');
});
