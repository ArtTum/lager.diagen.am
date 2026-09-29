<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id', 'action', 'entity', 'entity_id', 'before_data', 'after_data', 'ip_address'];
    protected function casts(): array { return ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime']; }
}
