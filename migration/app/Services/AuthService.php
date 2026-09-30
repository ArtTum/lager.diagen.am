<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AuthRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly AuthRepository $users) {}

    public function login(array $credentials): array
    {
        $user = $this->users->findByEmail($credentials['email']);
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Էլ. փոստը կամ գաղտնաբառը սխալ է։']]);
        }
        abort_unless($user->active, 403, 'Օգտահաշիվն ապաակտիվացված է։ Դիմեք համակարգի ադմինիստրատորին։');

        // Upgrade legacy PHP PASSWORD_DEFAULT hashes to the current Laravel cost
        // after successful authentication; never change hashes on a failed login.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']])->saveQuietly();
        }

        return ['token' => $user->createToken('diagen-lager-vue')->plainTextToken, 'user' => $this->payload($user)];
    }

    public function me(User $user): array
    {
        return $this->payload($user);
    }

    public function logout(User $user): void
    {
        $this->users->revokeCurrentToken($user);
    }

    private function payload(User $user): array
    {
        $user = $this->users->loadAuthContext($user);

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'active' => (bool) $user->active,
            'role' => ['name' => $user->role?->name, 'title' => $user->role?->title],
            'branch' => $user->branch ? ['id' => (int) $user->branch->id, 'name' => $user->branch->name, 'code' => $user->branch->code] : null,
            'location_id' => $user->currentLocationId(),
            'permissions' => $user->permissionMap(),
        ];
    }
}
