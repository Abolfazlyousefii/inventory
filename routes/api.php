<?php
use App\Http\Controllers\Integrations\AriyaSiteController; use Illuminate\Support\Facades\Route;
Route::prefix('integrations/ariya/v1')->middleware(['throttle:60,1','ariya.signature'])->group(function(){ Route::post('/orders',[AriyaSiteController::class,'storeOrder']); Route::get('/events/{eventId}',[AriyaSiteController::class,'event']); Route::get('/catalog/variants',[AriyaSiteController::class,'variants']); Route::get('/catalog/variants/{externalId}',[AriyaSiteController::class,'variant']); });
