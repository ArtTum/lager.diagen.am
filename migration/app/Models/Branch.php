<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['name', 'code', 'address', 'manager', 'phone', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }
}
