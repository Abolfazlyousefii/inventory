<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;
use App\Services\Sms\SmsManager;

/**
 * @mixin SmsManager
 */
class Sms extends Facade {
    protected static function getFacadeAccessor(): string {
        return SmsManager::class;
    }
}