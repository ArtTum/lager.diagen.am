<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReturn extends Model
{
    protected $table = 'returns';

    public $timestamps = false;

    protected $fillable = ['return_no', 'direction', 'from_location', 'supplier_id', 'product_id', 'lot_id', 'qty', 'reason', 'status', 'actor_id', 'created_at'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'created_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_location');
    }
}
