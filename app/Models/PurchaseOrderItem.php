<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'product_id', 'ordered_qty', 'received_qty', 'unit_cost'];

    protected function casts(): array
    {
        return ['ordered_qty' => 'decimal:3', 'received_qty' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
