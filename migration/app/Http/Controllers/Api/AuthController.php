<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:255']]);
        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Էլ. փոստը կամ գաղտնաբառը սխալ է։']]);
        }
        abort_unless($user->active, 403, 'Օգտահաշիվն ապաակտիվացված է։ Դիմեք համակարգի ադմինիստրատորին։');

        $token = $user->createToken('diagen-lager-vue')->plainTextToken;
        return response()->json(['data' => ['token' => $token, 'user' => $this->payload($user)]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Դուրս եք եկել համակարգից։']);
    }

    private function payload(User $user): array
    {
        $user->loadMissing(['role.permissions', 'branch']);
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
