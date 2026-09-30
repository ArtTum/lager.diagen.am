<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArchitectureLayeringTest extends TestCase
{
    public function test_api_controllers_delegate_to_application_services_and_do_not_validate_inline(): void
    {
        $controllers = File::allFiles(app_path('Http/Controllers/Api'));
        self::assertNotEmpty($controllers, 'API controllers should exist.');

        foreach ($controllers as $controller) {
            $source = File::get($controller->getPathname());
            $name = $controller->getFilename();

            self::assertStringContainsString('use App\\Services\\', $source, "{$name} should delegate application work to a service.");
            self::assertMatchesRegularExpression('/private\s+readonly\s+[A-Za-z0-9]+Service\s+\$/', $source, "{$name} should inject its application service.");
            self::assertDoesNotMatchRegularExpression('/->\s*validate\s*\(|Validator\s*::\s*make\s*\(/', $source, "{$name} must keep request validation outside the controller.");
        }
    }

    public function test_application_queries_use_repositories_and_models_not_db_table_shortcuts(): void
    {
        $directories = [
            app_path('Http/Controllers'),
            app_path('Services'),
            app_path('Repositories'),
        ];

        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $source = File::get($file->getPathname());
                self::assertDoesNotMatchRegularExpression(
                    '/\bDB\s*::\s*table\s*\(/',
                    $source,
                    "{$file->getRelativePathname()} should use Eloquent models/repositories instead of DB::table().",
                );
            }
        }
    }
}
