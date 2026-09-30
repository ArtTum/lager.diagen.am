<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLine extends Model
{
    protected $table = 'inventory_lines';

    public $timestamps = false;

    protected $fillable = ['session_id', 'product_id', 'lot_id', 'expected_qty', 'counted_qty', 'difference_reason', 'counted_lot_no', 'counted_expires_on', 'counted_supplier_id', 'counted_bin_location', 'counted_unit_cost'];

    protected function casts(): array
    {
        return ['expected_qty' => 'decimal:3', 'counted_qty' => 'decimal:3', 'counted_expires_on' => 'date', 'counted_unit_cost' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function countedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'counted_supplier_id');
    }
}
