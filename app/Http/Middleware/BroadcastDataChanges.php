<?php

namespace App\Http\Middleware;

use App\Services\DataChangeBroadcaster;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BroadcastDataChanges
{
    public function __construct(private readonly DataChangeBroadcaster $updates) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*')
            && ! $request->is('api/auth/*', 'api/broadcasting/*')
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $request->user() !== null
            && $response->isSuccessful()) {
            $this->updates->broadcast();
        }

        return $response;
    }
}
