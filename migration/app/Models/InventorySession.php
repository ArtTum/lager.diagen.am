<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySession extends Model
{
    protected $table = 'inventory_sessions';

    public $timestamps = false;

    protected $fillable = ['inventory_no', 'location_id', 'status', 'started_by', 'approved_by', 'started_at', 'closed_at', 'note'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryLine::class, 'session_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'location_id');
    }
}
