<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestItem extends Model
{
    protected $table = 'request_items';

    public $timestamps = false;

    protected $fillable = ['request_id', 'product_id', 'requested_qty', 'approved_qty', 'note'];

    protected function casts(): array
    {
        return ['requested_qty' => 'decimal:3', 'approved_qty' => 'decimal:3'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
