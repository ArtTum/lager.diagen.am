<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';
    public $timestamps = false;
    protected $fillable = ['code','barcode','name','category_id','subcategory','supplier_id','purchase_price','manufacturer','unit','package','min_qty','optimal_qty','max_qty','storage_conditions','refrigerated','lot_control','expiry_control','active'];
    protected function casts(): array { return ['purchase_price'=>'decimal:2','min_qty'=>'decimal:3','optimal_qty'=>'decimal:3','max_qty'=>'decimal:3','refrigerated'=>'boolean','lot_control'=>'boolean','expiry_control'=>'boolean','active'=>'boolean']; }
    public function lots(): HasMany { return $this->hasMany(StockLot::class, 'product_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'supplier_id'); }
}
