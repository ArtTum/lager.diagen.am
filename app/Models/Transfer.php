<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    protected $table = 'transfers';

    public $timestamps = false;

    protected $fillable = ['transfer_no', 'from_branch', 'to_branch', 'product_id', 'qty', 'status', 'requested_by', 'approved_by', 'shipped_by', 'received_by', 'reason', 'created_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'created_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class, 'transfer_id');
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
