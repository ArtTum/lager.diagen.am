<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    protected $table = 'receipt_items';

    public $timestamps = false;

    protected $fillable = ['receipt_id', 'purchase_order_item_id', 'product_id', 'lot_id', 'qty', 'unit_cost'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
