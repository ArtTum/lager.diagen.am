<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Repositories\PermissionRepository;
use App\Support\PermissionCatalog;
use Illuminate\Validation\ValidationException;

class PermissionService
{
    public function __construct(private readonly PermissionRepository $permissions) {}

    /**
     * Centralizes the role-code checks so route middleware, UI capability data,
     * and domain services use the same decision rules.
     */
    public function allows(User $user, string $permissionCode): bool
    {
        if (! $user->active || ! PermissionCatalog::contains($permissionCode)) {
            return false;
        }
        if ($user->currentLocationId() !== 0 && ! in_array($permissionCode, PermissionCatalog::branchAllowed(), true)) {
            return false;
        }
        // These capabilities remain central-only even if a role row is misconfigured.
        if (in_array($permissionCode, PermissionCatalog::centralOnly(), true) && $user->currentLocationId() !== 0) {
            return false;
        }
        if ($user->role?->name === 'admin') {
            // Keep API authorization aligned with the permission catalog sent to
            // the client. An unknown or removed code must never be implicitly granted.
            return $this->permissions->exists($permissionCode);
        }

        $this->permissions->loadForUser($user);

        return $user->role?->permissions->contains('code', $permissionCode) ?? false;
    }

    /** @return array<string, true> */
    public function mapFor(User $user): array
    {
        if (! $user->active) {
            return [];
        }
        if ($user->role?->name === 'admin') {
            $codes = $this->permissions->codes();
            if ($user->currentLocationId() !== 0) {
                $codes = $codes->reject(static fn (string $code): bool => in_array($code, PermissionCatalog::centralOnly(), true));
            }
        } else {
            $this->permissions->loadForUser($user);
            $codes = $user->role?->permissions->pluck('code') ?? collect();
        }
        $cataloguedCodes = PermissionCatalog::codes();
        $codes = $codes->filter(static fn (string $code): bool => in_array($code, $cataloguedCodes, true));
        if ($user->currentLocationId() !== 0) {
            $codes = $codes->filter(static fn (string $code): bool => in_array($code, PermissionCatalog::branchAllowed(), true));
        }

        return $codes->mapWithKeys(static fn (string $code): array => [$code => true])->all();
    }

    /**
     * Prevent privilege escalation while allowing an editor to preserve grants
     * that already exist on a role but are outside the editor's own scope.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $existingPermissions
     */
    public function assertAssignable(User $actor, array $permissions, array $existingPermissions = []): void
    {
        $unknownPermissions = array_diff($permissions, PermissionCatalog::codes());
        if ($unknownPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Նշված թույլտվություններից մեկը այլևս հասանելի չէ։'],
            ]);
        }

        $assignable = array_keys($actor->permissionMap());
        $outsideScope = array_diff($permissions, $assignable);
        $newOutsideScope = array_diff($outsideScope, $existingPermissions);

        if ($newOutsideScope !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Դուք չեք կարող նշանակել ձեր իրավունքներից դուրս թույլտվություններ։'],
            ]);
        }
    }

    /** A user may only assign roles whose effective grants fit within their own. */
    public function canAssignRole(User $actor, Role $role): bool
    {
        $assignable = array_keys($actor->permissionMap());
        if ($role->name === 'admin') {
            $grants = $this->permissions->codes()->all();
        } else {
            $this->permissions->loadForRole($role);
            $grants = $role->permissions->pluck('code')->all();
        }

        return array_diff($grants, $assignable) === [];
    }
}
