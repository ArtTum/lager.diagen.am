<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockRequest extends Model
{
    protected $table = 'stock_requests';

    public $timestamps = false;

    protected $fillable = ['request_no', 'branch_id', 'requested_by', 'status', 'urgency', 'reason', 'rejection_reason', 'reviewed_by', 'sent_by', 'received_by', 'sent_at', 'received_at', 'created_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class, 'request_id');
    }
}
