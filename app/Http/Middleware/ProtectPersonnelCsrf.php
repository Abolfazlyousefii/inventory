<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** New personnel writes require a session token even where legacy routes opt out. */
class ProtectPersonnelCsrf extends ValidateCsrfToken
{
    protected function inExceptArray($request)
    {
        return false;
    }
}
