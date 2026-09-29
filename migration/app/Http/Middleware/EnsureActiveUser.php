<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->active, 403, 'Օգտահաշիվն ապաակտիվացված է։ Դիմեք համակարգի ադմինիստրատորին։');
        return $next($request);
    }
}
