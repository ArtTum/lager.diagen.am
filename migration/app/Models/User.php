<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'role_id', 'branch_id', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'password' => 'hashed'];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function currentLocationId(): int
    {
        $branchId = (int) ($this->branch_id ?? 0);
        if ($branchId && $this->branch?->code === 'CENTRAL') return 0;
        return $branchId;
    }

    public function hasPermissionCode(string $code): bool
    {
        if ($this->role?->name === 'admin') return true;
        if ($code === 'inventory.approve' && $this->currentLocationId() !== 0) return false;
        return $this->role?->permissions()->where('code', $code)->exists() ?? false;
    }

    public function permissionMap(): array
    {
        if (! $this->relationLoaded('role')) $this->load('role.permissions');
        $codes = $this->role?->name === 'admin'
            ? Permission::query()->pluck('code')
            : ($this->role?->permissions->pluck('code') ?? collect());
        return $codes->mapWithKeys(static fn (string $code): array => [$code => true])->all();
    }
}
