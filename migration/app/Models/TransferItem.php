<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    protected $table = 'transfer_items';

    public $timestamps = false;

    protected $fillable = ['transfer_id', 'product_id', 'qty', 'received_qty', 'note'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'received_qty' => 'decimal:3'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
