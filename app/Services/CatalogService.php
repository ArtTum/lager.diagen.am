<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Repositories\CatalogRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly PermissionService $permissions,
    ) {}

    public function options(string $kind, User $actor): array
    {
        abort_unless(in_array($kind, ['branches', 'products', 'users', 'roles', 'transfers', 'requests', 'stock'], true), 404);
        $canViewPurchases = $actor->hasPermissionCode('purchases.view');
        $canViewSuppliers = $actor->hasPermissionCode('suppliers.view');
        $options = $this->catalog->options($kind, $canViewPurchases, $canViewPurchases || $canViewSuppliers);
        if (! $canViewPurchases && ! $canViewSuppliers) {
            $options['suppliers'] = collect();
        }
        $location = (int) $actor->currentLocationId();
        // Transfers need other active warehouses as destinations. The create
        // workflow separately verifies the actor owns the sending warehouse.
        if ($location > 0 && $kind !== 'transfers' && isset($options['branches'])) {
            $options['branches'] = $options['branches']->where('id', $location)->values();
        }
        if ($kind === 'requests' && isset($options['branches'])) {
            $options['branches'] = $options['branches']->where('code', '<>', 'CENTRAL')->values();
        }
        if ($kind === 'users') {
            $options['roles'] = $options['roles']
                ->filter(fn (Role $role): bool => $this->permissions->canAssignRole($actor, $role))
                ->values();
        }
        if ($kind === 'roles') {
            $assignable = $actor->permissionMap();
            $options['permissions'] = $options['permissions']
                ->filter(static fn ($permission): bool => isset($assignable[$permission->code]))
                ->values();
        }

        return $options;
    }

    public function show(string $kind, int $id, User $actor): Model|array
    {
        $record = $this->catalog->find($kind, $id);
        $this->assertLocationScope($actor, $kind, $record);
        if ($kind === 'products' && ! $actor->hasPermissionCode('purchases.view')) {
            $record->makeHidden('purchase_price');
        }

        return $kind === 'roles' ? $this->catalog->rolePayload($record) : $record;
    }

    public function store(User $actor, string $ip, string $kind, array $data): Model
    {
        $this->assertLocationScope($actor, $kind, null, $data);
        if ($kind === 'users') {
            $this->assertAssignableRole($actor, (int) $data['role_id']);
        }
        if ($kind === 'products' && ! $actor->hasPermissionCode('purchases.view')) {
            unset($data['purchase_price']);
            $data['purchase_price'] = 0;
        }
        $this->validateBusinessRules($kind, $data);

        return DB::transaction(function () use ($actor, $ip, $kind, $data): Model {
            $record = $this->catalog->create($kind, $data);
            $this->audit($actor, $ip, 'Ստեղծում', $kind, (int) $record->id, null, $this->safe($kind, $record->getAttributes()));
            if ($kind === 'products' && ! $actor->hasPermissionCode('purchases.view')) {
                $record->makeHidden('purchase_price');
            }

            return $record;
        });
    }

    public function update(User $actor, string $ip, string $kind, int $id, array $data): Model
    {
        if ($kind === 'products' && ! $actor->hasPermissionCode('purchases.view')) {
            unset($data['purchase_price']);
        }
        $this->validateBusinessRules($kind, $data);

        return DB::transaction(function () use ($actor, $ip, $kind, $id, $data): Model {
            $record = $this->catalog->find($kind, $id);
            $this->assertLocationScope($actor, $kind, $record, $data);
            if ($kind === 'users' && array_key_exists('role_id', $data)) {
                $this->assertAssignableRole($actor, (int) $data['role_id']);
            }
            $before = $this->safe($kind, $record->getAttributes());
            if ($kind === 'branches' && $record->code === 'CENTRAL'
                && ((array_key_exists('code', $data) && $data['code'] !== 'CENTRAL') || (array_key_exists('active', $data) && ! (bool) $data['active']))) {
                throw ValidationException::withMessages(['code' => ['Կենտրոնական պահեստի հիմնական կոդը և ակտիվությունը չեն փոփոխվում։']]);
            }
            if ($kind === 'users' && (int) $record->id === (int) $actor->id && array_key_exists('active', $data) && ! (bool) $data['active']) {
                throw ValidationException::withMessages(['active' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ հաշիվը։']]);
            }
            if ($record->active && array_key_exists('active', $data) && ! (bool) $data['active']) {
                $this->assertDeactivationAllowed($actor, $kind, $record);
            }
            $record = $this->catalog->update($record, $data);
            $this->audit($actor, $ip, 'Փոփոխություն', $kind, $id, $before, $this->safe($kind, $data));
            if ($kind === 'products' && ! $actor->hasPermissionCode('purchases.view')) {
                $record->makeHidden('purchase_price');
            }

            return $record;
        });
    }

    public function deactivate(User $actor, string $ip, string $kind, int $id): void
    {
        DB::transaction(function () use ($actor, $ip, $kind, $id): void {
            $record = $this->catalog->find($kind, $id);
            $this->assertLocationScope($actor, $kind, $record);
            $this->assertDeactivationAllowed($actor, $kind, $record);
            $before = ['active' => (bool) $record->active];
            $this->catalog->deactivate($record);
            $this->audit($actor, $ip, 'Ապաակտիվացում', $kind, $id, $before, ['active' => false]);
        });
    }

    public function createRole(User $actor, string $ip, array $data): array
    {
        abort_unless($actor->hasPermissionCode('roles.create'), 403, 'Նոր դեր ստեղծելու թույլտվություն չկա։');
        // The legacy workflow requires both creating the role record and being
        // allowed to configure role permissions. Keep the check server-side so
        // callers cannot bypass the UI's combined capability requirement.
        abort_unless($actor->hasPermissionCode('roles.edit'), 403, 'Դերի իրավունքները կարգավորելու թույլտվություն չկա։');
        $this->assertAssignablePermissions($actor, $data['permissions'] ?? []);

        return DB::transaction(function () use ($actor, $ip, $data): array {
            $role = $this->catalog->createRole(trim($data['title']), 'custom_'.bin2hex(random_bytes(8)));
            $permissions = $data['permissions'] ?? [];
            $this->catalog->syncRolePermissions($role, $permissions);
            $this->audit($actor, $ip, 'Նոր դեր ստեղծվեց', 'roles', (int) $role->id, null, ['title' => $role->title, 'permissions' => $permissions]);

            return $this->catalog->rolePayload($this->catalog->refreshRole($role));
        });
    }

    public function updateRolePermissions(User $actor, string $ip, int $id, array $data): array
    {
        abort_unless($actor->hasPermissionCode('roles.edit'), 403, 'Դերերի իրավունքները փոփոխելու թույլտվություն չկա։');

        return DB::transaction(function () use ($actor, $ip, $id, $data): array {
            /** @var Role $role */
            $role = $this->catalog->find('roles', $id);
            if ($role->name === 'admin') {
                throw ValidationException::withMessages(['role' => ['Ադմինիստրատորի լիարժեք իրավունքները չեն փոփոխվում։']]);
            }
            $before = $this->catalog->rolePermissions($role)->all();
            $this->assertAssignablePermissions($actor, $data['permissions'], $before);
            $assignable = array_keys($actor->permissionMap());
            $outsideScope = array_values(array_diff($before, $assignable));
            $permissions = array_values(array_unique([...$data['permissions'], ...$outsideScope]));
            sort($permissions);
            $this->catalog->syncRolePermissions($role, $permissions);
            $this->audit($actor, $ip, 'Իրավունքների փոփոխություն', 'roles', $id, $before, $permissions);

            return $this->catalog->rolePayload($this->catalog->refreshRole($role));
        });
    }

    public function createCategory(User $actor, string $ip, array $data): Category
    {
        return DB::transaction(function () use ($actor, $ip, $data): Category {
            $category = $this->catalog->createCategory([
                'name' => trim($data['name']),
                'parent_id' => isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null,
            ]);
            $this->audit($actor, $ip, 'Ապրանքային խումբ ստեղծվեց', 'categories', (int) $category->id, null, $category->only(['name', 'parent_id']));

            return $this->catalog->loadCategoryParent($category);
        });
    }

    public function categories(): array
    {
        return $this->catalog->categories()->map(static fn (Category $category): array => [
            'id' => (int) $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'parent' => $category->parent ? ['id' => (int) $category->parent->id, 'name' => $category->parent->name] : null,
            'products_count' => (int) $category->products_count,
            'children_count' => (int) $category->children_count,
        ])->all();
    }

    public function deleteCategory(User $actor, string $ip, int $id): void
    {
        DB::transaction(function () use ($actor, $ip, $id): void {
            $category = $this->catalog->findCategory($id);
            if ($this->catalog->categoryIsUsed($category)) {
                throw ValidationException::withMessages(['category' => ['Օգտագործվող տեսակը չի ջնջվում․ նախ տեղափոխեք ապրանքներն ու ենթատեսակները։']]);
            }
            $before = $category->only(['name', 'parent_id']);
            $this->catalog->deleteCategory($category);
            $this->audit($actor, $ip, 'Ապրանքային խումբը ջնջվեց', 'categories', $id, $before, null);
        });
    }

    private function validateBusinessRules(string $kind, array $data): void
    {
        if ($kind !== 'products') {
            return;
        }
        $maximum = (float) ($data['max_qty'] ?? 0);
        if ($maximum > 0 && ((float) ($data['min_qty'] ?? 0) > $maximum || (float) ($data['optimal_qty'] ?? 0) > $maximum)) {
            throw ValidationException::withMessages(['max_qty' => ['MIN-ը և OPTIMAL-ը չեն կարող գերազանցել MAX-ը։']]);
        }
    }

    private function assertLocationScope(User $actor, string $kind, ?Model $record = null, array $data = []): void
    {
        $location = (int) $actor->currentLocationId();
        if ($location < 1) {
            return;
        }

        if ($kind === 'branches') {
            abort_unless($record && (int) $record->id === $location, 403, 'Կարող եք կառավարել միայն ձեր մասնաճյուղի տվյալները։');
            if (array_key_exists('active', $data) && ! (bool) $data['active']) {
                throw ValidationException::withMessages(['active' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ մասնաճյուղը։']]);
            }
        }

        if ($kind === 'users') {
            if ($record) {
                abort_unless((int) $record->branch_id === $location, 403, 'Կարող եք կառավարել միայն ձեր մասնաճյուղի օգտատերերին։');
            }
            if (array_key_exists('branch_id', $data)) {
                abort_unless((int) $data['branch_id'] === $location, 403, 'Նոր օգտատերը պետք է պատկանի ձեր մասնաճյուղին։');
            }
        }
    }

    private function assertAssignablePermissions(User $actor, array $permissions, array $existingPermissions = []): void
    {
        $this->permissions->assertAssignable($actor, $permissions, $existingPermissions);
    }

    private function assertDeactivationAllowed(User $actor, string $kind, Model $record): void
    {
        if ($kind === 'branches' && (int) $actor->currentLocationId() > 0) {
            throw ValidationException::withMessages(['id' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ մասնաճյուղը։']]);
        }
        if ($kind === 'users' && (int) $record->id === (int) $actor->id) {
            throw ValidationException::withMessages(['id' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ հաշիվը։']]);
        }
        if ($kind === 'products' && $this->catalog->stockExistsForProduct((int) $record->id)) {
            throw ValidationException::withMessages(['id' => ['Մնացորդ ունեցող ապրանքը հնարավոր չէ ապաակտիվացնել։']]);
        }
        if ($kind === 'branches' && $record->code === 'CENTRAL') {
            throw ValidationException::withMessages(['id' => ['Կենտրոնական պահեստը հնարավոր չէ ապաակտիվացնել։']]);
        }
        if ($kind === 'branches' && $this->catalog->stockExistsForLocation((int) $record->id)) {
            throw ValidationException::withMessages(['id' => ['Մնացորդ ունեցող պահեստը հնարավոր չէ ապաակտիվացնել։']]);
        }
    }

    private function assertAssignableRole(User $actor, int $roleId): void
    {
        /** @var Role $role */
        $role = $this->catalog->find('roles', $roleId);
        if (! $this->permissions->canAssignRole($actor, $role)) {
            throw ValidationException::withMessages(['role_id' => ['Չեք կարող նշանակել ձեր թույլտվություններից ավելի լայն իրավունքներ ունեցող դեր։']]);
        }
    }

    private function safe(string $kind, array $data): array
    {
        if ($kind === 'users') {
            unset($data['password']);
        }
        if ($kind === 'suppliers') {
            unset($data['bank_details']);
        }

        return $data;
    }

    private function audit(User $actor, string $ip, string $action, string $entity, int $id, ?array $before, ?array $after): void
    {
        $this->catalog->createAuditEntry(['actor_id' => $actor->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }
}
