<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CatalogRepository
{
    public function createAuditEntry(array $attributes): AuditLog
    {
        return AuditLog::query()->create($attributes);
    }

    public function options(string $kind, bool $includeCosts = false, bool $includeSuppliers = true): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'parent_id']),
            'suppliers' => $includeSuppliers ? Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'roles' => Role::query()->orderBy('title')->get(['id', 'title', 'name']),
            'permissions' => $kind === 'roles' ? Permission::query()->orderBy('module')->orderBy('title')->get(['code', 'title', 'module']) : [],
            'products' => in_array($kind, ['products', 'transfers', 'requests', 'stock'], true)
                ? Product::query()->where('active', true)->orderBy('name')->get($includeCosts
                    ? ['id', 'code', 'name', 'unit', 'purchase_price', 'expiry_control']
                    : ['id', 'code', 'name', 'unit', 'expiry_control']) : [],
        ];
    }

    public function find(string $kind, int $id): Model
    {
        return $this->queryFor($kind)->findOrFail($id);
    }

    public function create(string $kind, array $data): Model
    {
        return $this->queryFor($kind)->create($data)->refresh();
    }

    public function update(Model $record, array $data): Model
    {
        // An empty optional password means "keep the current password". The
        // HTTP middleware converts the edit form's empty string to null.
        if ($record instanceof User && ($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        $record->fill($data)->save();
        if ($record instanceof User && ($record->wasChanged('password') || ! $record->active)) {
            $record->tokens()->delete();
        }

        return $record->refresh();
    }

    public function deactivate(Model $record): void
    {
        $record->forceFill(['active' => false])->save();
        if ($record instanceof User) {
            $record->tokens()->delete();
        }
    }

    public function createCategory(array $data): Category
    {
        return Category::query()->create($data);
    }

    public function findCategory(int $id): Category
    {
        return Category::query()->findOrFail($id);
    }

    public function categoryIsUsed(Category $category): bool
    {
        return $category->products()->exists() || $category->children()->exists();
    }

    public function deleteCategory(Category $category): void
    {
        $category->delete();
    }

    public function stockExistsForProduct(int $productId): bool
    {
        return StockLot::query()->where('product_id', $productId)->where('qty', '>', 0.00001)->exists();
    }

    public function stockExistsForLocation(int $locationId): bool
    {
        return StockLot::query()->where('location_id', $locationId)->where('qty', '>', 0.00001)->exists();
    }

    public function createRole(string $title, string $name): Role
    {
        return Role::query()->create(['name' => $name, 'title' => $title]);
    }

    public function rolePermissions(Role $role): Collection
    {
        return $role->permissions()->orderBy('code')->pluck('code');
    }

    public function syncRolePermissions(Role $role, array $codes): void
    {
        $permissionIds = Permission::query()->whereIn('code', $codes)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);
    }

    public function rolePayload(Role $role): array
    {
        $permissions = $this->rolePermissions($role)
            ->filter(static fn (string $code): bool => PermissionCatalog::contains($code))
            ->values()
            ->all();

        return [...$role->toArray(), 'permissions' => $permissions];
    }

    public function refreshRole(Role $role): Role
    {
        return $role->fresh();
    }

    public function loadCategoryParent(Category $category): Category
    {
        return $category->load('parent:id,name');
    }

    private function queryFor(string $kind)
    {
        return match ($kind) {
            'branches' => Branch::query(),
            'products' => Product::query(),
            'users' => User::query()->with(['role:id,title,name', 'branch:id,name,code']),
            'roles' => Role::query(),
            default => abort(404),
        };
    }
}
