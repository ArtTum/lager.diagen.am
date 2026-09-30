<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exportModules = collect(PermissionCatalog::moduleActions())
            ->filter(static fn (array $actions): bool => in_array('export', $actions, true))
            ->keys();

        foreach (Permission::query()->whereIn('module', $exportModules)->where('code', 'like', '%.view')->get() as $viewPermission) {
            $module = $viewPermission->module;
            $exportPermission = Permission::query()->firstOrCreate(
                ['code' => $module.'.export'],
                ['title' => Str::before($viewPermission->title, ' — ').' — Արտահանել', 'module' => $module],
            );

            // Before this capability existed, every viewer could export the same
            // page. Preserve that effective access while making it configurable.
            foreach (Role::query()->whereHas('permissions', static function ($query) use ($viewPermission): void {
                $query->whereKey($viewPermission->getKey());
            })->get() as $role) {
                $role->permissions()->syncWithoutDetaching([$exportPermission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        // Keep role grants and catalog rows intact on rollback. Operators may
        // have customized them after the migration was applied.
    }
};
