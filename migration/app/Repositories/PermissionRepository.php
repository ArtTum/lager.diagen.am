<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class PermissionRepository
{
    public function exists(string $code): bool
    {
        return Permission::query()->where('code', $code)->exists();
    }

    /** @return Collection<int, string> */
    public function codes(): Collection
    {
        return Permission::query()->orderBy('code')->pluck('code');
    }

    public function loadForUser(User $user): User
    {
        return $user->loadMissing('role.permissions');
    }

    public function loadForRole(Role $role): Role
    {
        return $role->loadMissing('permissions');
    }
}
