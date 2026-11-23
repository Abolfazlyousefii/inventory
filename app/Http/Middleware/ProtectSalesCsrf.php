<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Sales writes cannot inherit the broad exception retained by legacy modules. */
class ProtectSalesCsrf extends ValidateCsrfToken
{
    protected function inExceptArray($request)
    {
        return false;
    }
}
