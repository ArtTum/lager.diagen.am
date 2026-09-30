<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    public $timestamps = false;

    protected $fillable = ['order_no', 'supplier_id', 'status', 'ordered_on', 'expected_on', 'created_by', 'approved_by', 'approved_at', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date:Y-m-d', 'expected_on' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'purchase_order_id');
    }
}
