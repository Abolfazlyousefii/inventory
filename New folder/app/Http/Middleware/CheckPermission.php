<?php

namespace App\Http\Middleware;

use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user && PermissionCatalog::userHasPermission($user, $permission)) {
            return $next($request);
        }

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        abort(403, 'شما دسترسی لازم برای مشاهده این بخش را ندارید.');
    }

}
