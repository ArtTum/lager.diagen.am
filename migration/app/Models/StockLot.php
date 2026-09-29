<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLot extends Model
{
    protected $table = 'stock_lots';
    public $timestamps = false;
    protected $fillable = ['product_id','purchase_order_id','location_id','lot_no','expires_on','received_on','supplier_id','unit_cost','bin_location','qty'];
    protected function casts(): array { return ['expires_on'=>'date','received_on'=>'date','unit_cost'=>'decimal:2','qty'=>'decimal:3']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'supplier_id'); }
}
