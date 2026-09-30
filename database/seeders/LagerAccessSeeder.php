<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class LagerAccessSeeder extends Seeder
{
    private const ROLES = [
        'admin' => 'Համակարգի ադմինիստրատոր', 'manager' => 'Տնտեսական բաժնի պատասխանատու',
        'storekeeper' => 'Կենտրոնական պահեստապետ', 'branch' => 'Մասնաճյուղի պատասխանատու',
        'finance' => 'Ֆինանսական բաժին', 'viewer' => 'Դիտորդ',
    ];

    public function run(): void
    {
        foreach (PermissionCatalog::definitions() as $permission) {
            Permission::query()->firstOrCreate(
                ['code' => $permission['code']],
                ['title' => $permission['title'], 'module' => $permission['module']],
            );
        }

        foreach (self::ROLES as $name => $title) {
            $role = Role::query()->firstOrCreate(['name' => $name], ['title' => $title]);
            // Existing grants are organization data. Bootstrap only roles that
            // have not yet been configured, and leave customized roles intact.
            if ($role->permissions()->exists()) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching($this->defaultPermissionIds($name));
        }
    }

    /** @return list<int> */
    private function defaultPermissionIds(string $role): array
    {
        $selected = match ($role) {
            'admin' => PermissionCatalog::codes(),
            'manager' => array_values(array_filter(PermissionCatalog::codes(), static fn (string $code): bool => ! str_ends_with($code, '.delete')
                && ! in_array($code, ['users.create', 'users.edit', 'roles.create', 'roles.edit'], true))),
            'storekeeper' => [
                'dashboard.view', 'products.view', 'products.export', 'stock.view', 'stock.create', 'stock.export',
                'receipts.view', 'receipts.create', 'purchases.view', 'purchases.create',
                'requests.view', 'requests.create', 'requests.edit', 'requests.export',
                'movements.view', 'movements.edit', 'movements.export',
                'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export',
                'expiry.view', 'expiry.export', 'returns.view', 'returns.create', 'returns.export',
                'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.export',
                'notifications.view', 'reports.view', 'reports.export',
            ],
            'branch' => PermissionCatalog::branchAllowed(),
            'finance' => [
                'dashboard.view', 'suppliers.view', 'products.view', 'products.export',
                'purchases.view', 'purchases.approve', 'purchases.export',
                'receipts.view', 'receipts.export', 'stock.view', 'stock.export',
                'movements.view', 'movements.export', 'expiry.view', 'expiry.export',
                'returns.view', 'returns.export', 'notifications.view', 'reports.view', 'reports.export',
            ],
            'viewer' => array_values(array_filter(PermissionCatalog::codes(), static fn (string $code): bool => str_ends_with($code, '.view'))),
            default => [],
        };

        return Permission::query()->whereIn('code', $selected)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
