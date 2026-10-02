<?php

use App\Http\Middleware\BroadcastDataChanges;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum', 'active.user'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', BroadcastDataChanges::class);
        $middleware->redirectGuestsTo(static fn ($request): ?string => $request->is('api/*') ? null : route('login'),
        );
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Keep API errors JSON-shaped for the Vue SPA.
        $exceptions->shouldRenderJsonWhen(static fn ($request): bool => $request->is('api/*') || $request->expectsJson());
    })->create();
