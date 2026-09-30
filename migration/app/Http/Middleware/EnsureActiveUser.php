<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->active) {
            $token = $user?->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }

            abort(401, 'Օգտահաշիվն ապաակտիվացված է։ Կրկին մուտք գործել հնարավոր չէ։ Դիմեք համակարգի ադմինիստրատորին։');
        }

        return $next($request);
    }
}
