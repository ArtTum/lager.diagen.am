<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExportPermissionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        parent::tearDown();
    }

    public function test_export_migration_preserves_existing_viewers_export_access_only_for_their_modules(): void
    {
        $reader = Role::query()->create(['name' => 'reader', 'title' => 'Դիտող']);
        $limited = Role::query()->create(['name' => 'limited', 'title' => 'Սահմանափակ']);
        $stockView = Permission::query()->create(['code' => 'stock.view', 'title' => 'Մնացորդներ — Դիտել', 'module' => 'stock']);
        $reportsView = Permission::query()->create(['code' => 'reports.view', 'title' => 'Հաշվետվություններ — Դիտել', 'module' => 'reports']);
        $dashboardView = Permission::query()->create(['code' => 'dashboard.view', 'title' => 'Գլխավոր վահանակ — Դիտել', 'module' => 'dashboard']);
        $suppliersView = Permission::query()->create(['code' => 'suppliers.view', 'title' => 'Մատակարարներ — Դիտել', 'module' => 'suppliers']);
        $notificationsView = Permission::query()->create(['code' => 'notifications.view', 'title' => 'Ծանուցումներ — Դիտել', 'module' => 'notifications']);
        $reader->permissions()->attach([$stockView->id, $reportsView->id, $dashboardView->id, $suppliersView->id, $notificationsView->id]);
        $limited->permissions()->attach([$stockView, $dashboardView, $suppliersView, $notificationsView]);

        $migration = require base_path('database/migrations/2026_09_30_000005_add_export_permissions.php');
        $migration->up();

        self::assertTrue($reader->permissions()->where('code', 'stock.export')->exists());
        self::assertTrue($reader->permissions()->where('code', 'reports.export')->exists());
        self::assertTrue($limited->permissions()->where('code', 'stock.export')->exists());
        self::assertFalse($limited->permissions()->where('code', 'reports.export')->exists());
        self::assertSame('Մնացորդներ — Արտահանել', Permission::query()->where('code', 'stock.export')->value('title'));
        self::assertSame(['reports.export', 'stock.export'], Permission::query()->where('code', 'like', '%.export')->orderBy('code')->pluck('code')->all());
    }
}
