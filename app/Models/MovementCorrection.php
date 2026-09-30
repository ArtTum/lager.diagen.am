<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementCorrection extends Model
{
    protected $table = 'movement_corrections';

    public $timestamps = false;

    protected $fillable = ['movement_id', 'correction_no', 'reason', 'actor_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'movement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
