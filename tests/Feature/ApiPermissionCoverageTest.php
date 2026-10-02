<?php

namespace Tests\Feature;

use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiPermissionCoverageTest extends TestCase
{
    public function test_every_api_route_uses_the_permission_for_its_module_and_action(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/'));

        foreach ($apiRoutes as $route) {
            $uri = $route->uri();
            if (in_array($uri, ['api/auth/login', 'api/auth/me', 'api/auth/logout', 'api/broadcasting/auth'], true)) {
                continue;
            }

            $permission = collect($route->middleware())
                ->first(static fn (string $name): bool => str_starts_with($name, 'permission:'));
            $this->assertNotNull($permission, $uri.' must declare a permission before its action can be checked.');

            $expected = $this->expectedPermission($uri, $route->methods());
            $this->assertSame(
                'permission:'.$expected,
                $permission,
                implode('|', $route->methods()).' '.$uri.' must require '.$expected.'.',
            );
        }
    }

    public function test_every_non_auth_api_endpoint_requires_authentication_active_account_and_a_permission(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/'));

        $this->assertNotEmpty($apiRoutes, 'Expected the Laravel API routes to be registered.');

        foreach ($apiRoutes as $route) {
            $uri = $route->uri();
            if (in_array($uri, ['api/auth/login', 'api/auth/me', 'api/auth/logout', 'api/broadcasting/auth'], true)) {
                continue;
            }

            $middleware = $route->middleware();
            $label = implode('|', $route->methods()).' '.$uri;

            $this->assertContains('auth:sanctum', $middleware, "$label must require Sanctum authentication.");
            $this->assertContains('active.user', $middleware, "$label must reject inactive accounts.");
            $this->assertTrue(
                collect($middleware)->contains(static fn (string $name): bool => str_starts_with($name, 'permission:')),
                "$label must require an explicit permission.",
            );
        }
    }

    public function test_auth_endpoints_keep_the_expected_access_controls(): void
    {
        $login = Route::getRoutes()->match(request()->create('/api/auth/login', 'POST'));
        $loginMiddleware = $login->middleware();
        $this->assertContains('throttle:10,1', $loginMiddleware);

        foreach (['/api/auth/me' => 'GET', '/api/auth/logout' => 'POST'] as $uri => $method) {
            $route = Route::getRoutes()->match(request()->create($uri, $method));
            $middleware = $route->middleware();

            $this->assertContains('auth:sanctum', $middleware, "$method $uri must require Sanctum authentication.");
            $this->assertContains('active.user', $middleware, "$method $uri must reject inactive accounts.");
        }
    }

    public function test_broadcast_authorization_requires_authentication_and_an_active_account(): void
    {
        $route = Route::getRoutes()->match(request()->create('/api/broadcasting/auth', 'POST'));
        $middleware = $route->middleware();

        self::assertContains('auth:sanctum', $middleware);
        self::assertContains('active.user', $middleware);
    }

    public function test_every_api_permission_is_defined_in_the_shared_catalog(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/'));

        foreach ($apiRoutes as $route) {
            foreach ($route->middleware() as $middleware) {
                if (! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                $permission = substr($middleware, strlen('permission:'));
                $this->assertTrue(
                    PermissionCatalog::contains($permission),
                    implode('|', $route->methods()).' '.$route->uri()." references unknown permission {$permission}.",
                );
            }
        }
    }

    /** @param list<string> $methods */
    private function expectedPermission(string $uri, array $methods): string
    {
        $path = substr($uri, strlen('api/'));
        $segments = explode('/', $path);
        $method = in_array('GET', $methods, true) ? 'GET' : $methods[0];

        if (($segments[0] ?? null) === 'pages') {
            $module = $segments[1];
        } elseif (($segments[0] ?? null) === 'catalog') {
            $module = $segments[1];
        } elseif (($segments[0] ?? null) === 'purchasing') {
            $module = $segments[1];
        } elseif (($segments[0] ?? null) === 'categories') {
            $module = 'products';
        } else {
            $module = $segments[0];
        }

        $last = end($segments);
        if ($path === 'requests/suggestions') {
            $action = 'create';
        } elseif ($path === 'notifications/read') {
            // Read receipts are per-user state; the catalog has no separate write capability.
            $action = 'view';
        } elseif (in_array($last, ['export'], true)) {
            $action = 'export';
        } elseif (in_array($last, ['approve', 'review'], true)) {
            $action = 'approve';
        } elseif (in_array($last, ['adjust', 'count', 'draft', 'receive', 'reverse', 'ship'], true)
            || preg_match('#\Arequests/\{[^/]+\}/\{action\}\z#', $path)) {
            $action = 'edit';
        } else {
            $action = match ($method) {
                'GET', 'HEAD' => 'view',
                'POST' => 'create',
                'PUT', 'PATCH' => 'edit',
                'DELETE' => 'delete',
                default => throw new \LogicException("Unexpected API method {$method} for {$uri}."),
            };
        }

        return $module.'.'.$action;
    }
}
