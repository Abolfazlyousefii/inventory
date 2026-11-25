<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Notification writes require CSRF protection despite the legacy web exemption. */
class ProtectNotificationCsrf extends ValidateCsrfToken
{
    protected function inExceptArray($request)
    {
        return false;
    }
}
