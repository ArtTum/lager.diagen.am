<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user()?->hasPermissionCode($permission), 403, 'Այս գործողությունը հասանելի չէ ձեր դերով։');
        return $next($request);
    }
}
