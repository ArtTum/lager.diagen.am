<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyModuleParityTest extends TestCase
{
    public function test_every_legacy_module_has_a_vue_page_and_laravel_api_route(): void
    {
        $legacySource = File::get(base_path('tests/Fixtures/legacy-modules.php'));
        self::assertSame(1, preg_match('/\$modules\s*=\s*\[(.*?)\n\];/s', $legacySource, $moduleBlock));
        preg_match_all("/'([a-z_]+)'\s*=>\s*\['[^']*',\s*'[^']*',\s*'([^']+)'\]/", $moduleBlock[1], $moduleMatches);
        $modules = $moduleMatches[1];
        self::assertNotEmpty($modules, 'The legacy module catalog should be readable for parity checks.');

        $routerSource = File::get(resource_path('js/router/index.js'));

        foreach ($modules as $index => $module) {
            $legacyPermission = $moduleMatches[2][$index];
            $spaPath = match ($module) {
                'dashboard' => '/dashboard',
                'suppliers' => '/suppliers',
                'dispatch' => '/requests/:request/dispatch',
                default => null,
            };

            if ($spaPath !== null) {
                self::assertStringContainsString("path: '{$spaPath}'", $routerSource, "Legacy module {$module} needs its dedicated Vue route.");
            } else {
                self::assertStringContainsString("['{$module}',", $routerSource, "Legacy module {$module} needs a Vue page route.");
                self::assertSame($module.'.view', $legacyPermission, "Legacy module {$module} must keep its own view permission.");
                self::assertStringContainsString('permission: `${path}.view`', $routerSource, 'Generic Vue routes must enforce their module view permission.');
            }

            $dedicatedPermission = match ($module) {
                'dashboard' => "meta: { permission: '{$legacyPermission}'",
                'suppliers' => "meta: { permission: '{$legacyPermission}'",
                'dispatch' => "meta: { permission: '{$legacyPermission}'",
                default => null,
            };
            if ($dedicatedPermission !== null) {
                self::assertStringContainsString($dedicatedPermission, $routerSource, "Vue route for {$module} must retain {$legacyPermission}.");
            }

            $apiPath = match ($module) {
                'dashboard' => '/api/dashboard',
                'suppliers' => '/api/suppliers',
                'dispatch' => '/api/requests/1/dispatch-document',
                'notifications' => '/api/notifications',
                'reports' => '/api/reports',
                default => '/api/pages/'.$module,
            };
            $request = Request::create($apiPath, 'GET');
            $apiRoute = collect(Route::getRoutes()->getRoutes())
                ->first(static fn ($route): bool => str_starts_with($route->uri(), 'api/') && $route->matches($request));

            self::assertNotNull($apiRoute, "Legacy module {$module} needs a Laravel API route for its page data.");
        }
    }
}
