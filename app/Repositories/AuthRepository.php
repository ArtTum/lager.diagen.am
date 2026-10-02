<?php

namespace App\Repositories;

use App\Models\User;

class AuthRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->with(['role.permissions', 'branch'])->where('email', $email)->first();
    }

    public function findByEmailForUpdate(string $email): ?User
    {
        return User::query()->with(['role.permissions', 'branch'])->where('email', $email)->lockForUpdate()->first();
    }

    public function loadAuthContext(User $user): User
    {
        return $user->loadMissing(['role.permissions', 'branch']);
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
