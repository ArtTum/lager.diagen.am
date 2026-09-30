<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless(
            $user && app(PermissionService::class)->allows($user, $permission),
            403,
            'Այս գործողությունը հասանելի չէ ձեր դերով։',
        );

        return $next($request);
    }
}
