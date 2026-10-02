<?php

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'branch_id', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function setPasswordAttribute(#[\SensitiveParameter] string $value): void
    {
        // The source DB was inspected read-only: all existing hashes are PHP PASSWORD_DEFAULT bcrypt/cost 10.
        // Preserve only that exact legacy format during imports; hash other input as a password.
        $info = password_get_info($value);
        $isLegacyDefaultHash = ($info['algoName'] ?? null) === 'bcrypt'
            && ($info['options']['cost'] ?? null) === 10;

        $this->attributes['password'] = $isLegacyDefaultHash ? $value : Hash::make($value);
    }

    public function setPlainPassword(#[\SensitiveParameter] string $value): static
    {
        $this->attributes['password'] = Hash::make($value);

        return $this;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currentLocationId(): int
    {
        $branchId = (int) ($this->branch_id ?? 0);
        if ($branchId && $this->branch?->code === 'CENTRAL') {
            return 0;
        }

        return $branchId;
    }

    public function hasPermissionCode(string $code): bool
    {
        return app(PermissionService::class)->allows($this, $code);
    }

    public function permissionMap(): array
    {
        return app(PermissionService::class)->mapFor($this);
    }
}
