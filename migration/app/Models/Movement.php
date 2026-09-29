<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    protected $table = 'movements';
    public $timestamps = false;
    protected $fillable = ['movement_no', 'type', 'product_id', 'lot_id', 'from_location', 'to_location', 'qty', 'unit_cost', 'reference', 'reason', 'actor_id', 'happened_at', 'created_at'];
    protected function casts(): array { return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'happened_at' => 'datetime']; }
    public function lot(): BelongsTo { return $this->belongsTo(StockLot::class, 'lot_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
}
