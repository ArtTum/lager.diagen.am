<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'code', 'address', 'manager', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class, 'location_id');
    }
}
